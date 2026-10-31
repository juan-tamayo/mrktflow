<?php

declare(strict_types=1);

namespace App\Domain\ShoppingTools\Actions;

use App\Domain\Platform\Actions\UpdateDeliveryPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Watch subscriptions for stock and price alerts (SHOP-03).
 *
 * A subscription row is the per-variant expression of an opt-in —
 * never a consent store of its own. Consent is written only through
 * UpdateDeliveryPreference, one purpose per alert type (`stock` →
 * `stock_alert`, `price` → `price_alert`), so the two alerts are
 * separate opt-ins: subscribing to one never grants the other.
 *
 * An unverified contact subscribes into `pending` rows and is
 * granted nothing; the emailed challenge (VerifyWatchEmail) is the
 * double opt-in that verifies and activates. A contact that is
 * already verified subscribes active in-session with `watch_options`
 * provenance — deliberately not a protected source, so subscribing
 * can never clear an `opt_out`/`manual` suppression, and
 * DeliveryPolicy stays the single gate that decides alertability.
 * A price subscription snapshots the variant's purchasable price as
 * its baseline when it becomes active; T057 compares against it.
 * Subscribing is idempotent per (contact, variant, type), and only
 * a listed variant can be watched — draft, archived and unknown
 * variants fail with the wishlist's generic message so the surface
 * cannot probe unpublished catalog content.
 */
final class SubscribeToWatch
{
    /** @var array<string, string> */
    public const PURPOSE_BY_TYPE = ['stock' => 'stock_alert', 'price' => 'price_alert'];

    /** @var list<string> */
    private const TYPES = ['stock', 'price'];

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: string, contact_id: string, subscriptions: list<array{id: string, alert_type: string, status: string}>, verification_token: ?string}
     */
    public function subscribe(array $input): array
    {
        if (isset($input['email']) && is_string($input['email'])) {
            // The consent layer's normalization, applied before
            // validation so a padded or cased address is the same
            // contact everywhere in the platform.
            $input['email'] = mb_strtolower(trim($input['email']), 'UTF-8');
        }
        $validated = Validator::make($input, [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['string', Rule::in(self::TYPES)],
            'customer_id' => ['sometimes', 'nullable', 'uuid'],
        ])->validate();
        $email = (string) $validated['email'];
        /** @var list<string> $types */
        $types = array_values(array_unique($validated['types']));
        sort($types);
        $customerId = isset($validated['customer_id']) && is_string($validated['customer_id'])
            ? $validated['customer_id']
            : null;
        $variantId = $this->listedVariantId($input);

        return DB::transaction(function () use ($email, $types, $customerId, $variantId): array {
            $preferences = app(UpdateDeliveryPreference::class);
            $contactId = $preferences->ensureContact($email, $customerId);
            $contact = DB::table('delivery_contacts')->where('id', $contactId)->lockForUpdate()->first();
            $verified = $contact !== null && $contact->email_verified_at !== null;
            $accountProven = false;
            if (! $verified && $customerId !== null) {
                // A signed-in customer whose account email is verified
                // proves the contact the same way the preference
                // center does; anything less stays a pending guest.
                $account = DB::table('customer_accounts')->where('id', $customerId)->first();
                if ($account !== null && (bool) $account->is_active
                    && $account->email_verified_at !== null
                    && mb_strtolower(trim((string) $account->email), 'UTF-8') === $email) {
                    $preferences->markVerified($contactId, 'customer_account', 'customer', $customerId);
                    $verified = true;
                    $accountProven = true;
                }
            }
            $variant = DB::table('product_variants')->where('id', $variantId)->first();
            $subscriptions = [];
            foreach ($types as $type) {
                $existing = DB::table('watch_subscriptions')
                    ->where('contact_id', $contactId)
                    ->where('variant_id', $variantId)
                    ->where('alert_type', $type)
                    ->lockForUpdate()
                    ->first();
                $status = $verified ? 'active' : 'pending';
                if ($existing === null) {
                    $id = (string) Str::uuid();
                    $row = [
                        'id' => $id,
                        'contact_id' => $contactId,
                        'variant_id' => $variantId,
                        'alert_type' => $type,
                        'status' => $status,
                        'baseline_amount' => null,
                        'baseline_currency' => null,
                        'baseline_exponent' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if ($verified && $type === 'price' && $variant !== null) {
                        $row['baseline_amount'] = $variant->price_amount;
                        $row['baseline_currency'] = $variant->currency;
                        $row['baseline_exponent'] = $variant->exponent;
                    }
                    DB::table('watch_subscriptions')->insert($row);
                    $subscriptions[] = ['id' => $id, 'alert_type' => $type, 'status' => $status];
                } else {
                    if ($existing->status !== $status) {
                        $update = ['status' => $status, 'updated_at' => now()];
                        if ($verified && $type === 'price' && $variant !== null) {
                            $update['baseline_amount'] = $variant->price_amount;
                            $update['baseline_currency'] = $variant->currency;
                            $update['baseline_exponent'] = $variant->exponent;
                        }
                        DB::table('watch_subscriptions')->where('id', $existing->id)->update($update);
                    }
                    $subscriptions[] = ['id' => (string) $existing->id, 'alert_type' => $type, 'status' => $status];
                }
                if ($verified) {
                    $preferences->grant(
                        $contactId,
                        self::PURPOSE_BY_TYPE[$type],
                        'watch_options',
                        $accountProven ? 'customer_account' : 'verified_contact',
                        $customerId !== null ? 'customer' : 'guest',
                        $customerId,
                    );
                }
            }
            $token = null;
            if (! $verified) {
                $challenge = app(VerifyWatchEmail::class)->issue($contactId);
                $token = $challenge['token'];
            }

            return [
                'status' => $verified ? 'active' : 'verification_required',
                'contact_id' => $contactId,
                'subscriptions' => $subscriptions,
                'verification_token' => $token,
            ];
        });
    }

    /**
     * Cancels one subscription owned by the contact. Cancelling is a
     * per-variant act: the purpose grant is left alone (other watched
     * variants keep it), and DeliveryPolicy remains the send gate.
     */
    public function cancel(string $contactId, string $subscriptionId): bool
    {
        if (! Str::isUuid($contactId) || ! Str::isUuid($subscriptionId)) {
            return false;
        }

        return DB::transaction(function () use ($contactId, $subscriptionId): bool {
            $contact = DB::table('delivery_contacts')->where('id', $contactId)->lockForUpdate()->first();
            if ($contact === null) {
                return false;
            }
            $subscription = DB::table('watch_subscriptions')
                ->where('id', $subscriptionId)
                ->where('contact_id', $contactId)
                ->lockForUpdate()
                ->first();
            if ($subscription === null || $subscription->status === 'cancelled') {
                return false;
            }
            DB::table('watch_subscriptions')->where('id', $subscriptionId)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            return true;
        });
    }

    /**
     * The wishlist's listed-variant rule, unchanged: a published
     * product's listed variant, with one generic failure for draft,
     * archived and unknown ids alike.
     *
     * @param  array<string, mixed>  $input
     */
    private function listedVariantId(array $input): string
    {
        $variantId = $input['variant_id'] ?? null;
        if (is_string($variantId) && $variantId !== '') {
            $listed = DB::table('product_variants')
                ->join('products', 'product_variants.product_id', '=', 'products.id')
                ->where('product_variants.id', $variantId)
                ->where('products.status', 'published')
                ->where('product_variants.available', true)
                ->exists();
            if (! $listed) {
                throw ValidationException::withMessages([
                    'variant_id' => 'This item is no longer available.',
                ]);
            }

            return $variantId;
        }
        $productId = $input['product_id'] ?? null;
        if (is_string($productId) && $productId !== '') {
            $variant = DB::table('product_variants')
                ->join('products', 'product_variants.product_id', '=', 'products.id')
                ->where('product_variants.product_id', $productId)
                ->where('products.status', 'published')
                ->where('product_variants.available', true)
                ->orderByDesc('product_variants.is_default')
                ->orderBy('product_variants.created_at')
                ->orderBy('product_variants.id')
                ->select('product_variants.id')
                ->first();
            if ($variant === null) {
                throw ValidationException::withMessages([
                    'product_id' => 'This item is no longer available.',
                ]);
            }

            return (string) $variant->id;
        }

        throw ValidationException::withMessages([
            'variant_id' => 'Choose an item to watch.',
        ]);
    }
}
