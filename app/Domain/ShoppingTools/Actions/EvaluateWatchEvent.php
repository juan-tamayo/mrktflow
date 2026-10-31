<?php

declare(strict_types=1);

namespace App\Domain\ShoppingTools\Actions;

use App\Domain\Catalog\Support\VariantAvailability;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Platform\Support\DeliveryPolicy;
use App\Jobs\SendWatchAlert;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Watch alert evaluation for one catalog change (SHOP-04, OPS-01).
 *
 * Callers are the merchant write paths, and only for changes SHOP-04
 * names as alert sources: a stock evaluation happens solely on an
 * availability-entering change the caller has already established
 * from the committed inventory position (merchant receipt/restock via
 * AdjustStock or the returns restock act, a variant re-listing, or a
 * product entering publication). The checkout reservation lifecycle
 * never calls this class, so a transient hold/release cannot alert;
 * eligibility here always re-reads the committed position
 * (on_hand − reserved), never a transient one.
 *
 * An intent is created only for a subscription that passes every
 * gate right now: subscription `active`, variant still listed
 * (published product, listed variant), DeliveryPolicy allows the
 * purpose for the contact, and the condition holds against current
 * state — for price, the current purchasable price is strictly below
 * the subscription's activation baseline with identical currency and
 * exponent (a rise, or a currency/exponent change, is never a drop).
 * Two guards bound repeats: the intent key
 * `watch-alert:{subscriptionId}:{eventKey}` is unique, so replaying
 * one catalog event dedupes to a single intent; and the SHOP-04 cap
 * allows one alert of each type per variant/recipient per 24 hours,
 * enforced per subscription as "no queued/sending/sent intent inside
 * the window" — cancelled or failed intents never consume the cap.
 * Sending itself is DeliverWatchAlert's job; it rechecks everything
 * again immediately before the mail leaves.
 */
final class EvaluateWatchEvent
{
    /**
     * Evaluates stock subscriptions for a variant the caller has
     * established just entered availability.
     *
     * @return list<string> created intent ids
     */
    public function stock(string $variantId, string $eventKey): array
    {
        return $this->evaluate($variantId, 'stock', $eventKey);
    }

    /**
     * Evaluates price subscriptions for a variant after a catalog
     * write; the baseline comparison is itself the eligibility test,
     * so saves that do not produce a drop create nothing.
     *
     * @return list<string> created intent ids
     */
    public function price(string $variantId, string $eventKey): array
    {
        return $this->evaluate($variantId, 'price', $eventKey);
    }

    /**
     * Evaluates every variant of a product after a product-level
     * save: price for all variants, stock only when the caller has
     * established the save entered publication.
     *
     * @return list<string> created intent ids
     */
    public function product(string $productId, string $eventKey, bool $stockEntered): array
    {
        $variantIds = DB::table('product_variants')->where('product_id', $productId)->pluck('id');
        $created = [];
        foreach ($variantIds as $variantId) {
            if (! is_string($variantId)) {
                continue;
            }
            $created = [...$created, ...$this->price($variantId, $eventKey)];
            if ($stockEntered) {
                $created = [...$created, ...$this->stock($variantId, $eventKey)];
            }
        }

        return $created;
    }

    /**
     * @return list<string> created intent ids
     */
    private function evaluate(string $variantId, string $alertType, string $eventKey): array
    {
        if (! Str::isUuid($variantId)) {
            return [];
        }
        $created = DB::transaction(function () use ($variantId, $alertType, $eventKey): array {
            $variant = DB::table('product_variants')
                ->join('products', 'product_variants.product_id', '=', 'products.id')
                ->leftJoin('inventory_balances', 'product_variants.id', '=', 'inventory_balances.variant_id')
                ->where('product_variants.id', $variantId)
                ->lockForUpdate()
                ->select([
                    'product_variants.id',
                    'product_variants.available as variant_listed',
                    'product_variants.tracks_inventory',
                    'product_variants.price_amount',
                    'product_variants.currency',
                    'product_variants.exponent',
                    'products.status as product_status',
                    'products.type as product_type',
                    'inventory_balances.on_hand',
                    'inventory_balances.reserved',
                ])
                ->first();
            if ($variant === null || (string) $variant->product_status !== 'published' || ! (bool) $variant->variant_listed) {
                return [];
            }
            $subscriptions = DB::table('watch_subscriptions')
                ->where('variant_id', $variantId)
                ->where('alert_type', $alertType)
                ->where('status', 'active')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($subscriptions->isEmpty()) {
                return [];
            }
            $policy = app(DeliveryPolicy::class);
            $purpose = SubscribeToWatch::PURPOSE_BY_TYPE[$alertType];
            $created = [];
            foreach ($subscriptions as $subscription) {
                $contact = DB::table('delivery_contacts')->where('id', $subscription->contact_id)->first();
                if ($contact === null || ! $policy->marketingAllowed((string) $contact->email, $purpose)) {
                    continue;
                }
                if (! $this->conditionHolds($alertType, $variant, $subscription)) {
                    continue;
                }
                $recent = DB::table('watch_alert_intents')
                    ->where('subscription_id', $subscription->id)
                    ->whereIn('status', ['queued', 'sending', 'sent'])
                    ->where('created_at', '>=', now()->subDay())
                    ->exists();
                if ($recent) {
                    continue;
                }
                $intentKey = 'watch-alert:'.$subscription->id.':'.$eventKey;
                if (DB::table('watch_alert_intents')->where('intent_key', $intentKey)->exists()) {
                    continue;
                }
                $id = (string) Str::uuid();
                try {
                    DB::table('watch_alert_intents')->insert([
                        'id' => $id,
                        'subscription_id' => (string) $subscription->id,
                        'contact_id' => (string) $subscription->contact_id,
                        'variant_id' => $variantId,
                        'alert_type' => $alertType,
                        'template' => 'watch-alert',
                        'recipient' => (string) $contact->email,
                        'intent_key' => $intentKey,
                        'event_key' => $eventKey,
                        'kind' => 'event',
                        'status' => 'queued',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    continue;
                }
                app(RecordEvent::class)->record('platform.audit.recorded', 'watch:alert:'.$id, [
                    'action' => 'shopping.watch_alert_queued',
                    'subject_type' => 'watch_subscription',
                    'subject_id' => (string) $subscription->id,
                    'actor_type' => 'system',
                    'actor_id' => null,
                ]);
                $created[] = $id;
            }

            return $created;
        });
        foreach ($created as $intentId) {
            SendWatchAlert::dispatch($intentId)->afterCommit();
        }

        return $created;
    }

    private function conditionHolds(string $alertType, object $variant, object $subscription): bool
    {
        $v = get_object_vars($variant);
        $s = get_object_vars($subscription);
        if ($alertType === 'price') {
            return ($s['baseline_amount'] ?? null) !== null
                && (string) ($v['currency'] ?? '') === (string) ($s['baseline_currency'] ?? '')
                && (int) ($v['exponent'] ?? -1) === (int) ($s['baseline_exponent'] ?? -2)
                && (int) ($v['price_amount'] ?? 0) < (int) $s['baseline_amount'];
        }
        $digital = (string) ($v['product_type'] ?? '') === 'digital';
        $hasReadyFile = $digital && DB::table('variant_digital_assets')
            ->join('digital_asset_versions', 'variant_digital_assets.digital_asset_version_id', '=', 'digital_asset_versions.id')
            ->where('variant_digital_assets.variant_id', (string) ($v['id'] ?? ''))
            ->where('digital_asset_versions.status', 'ready')
            ->exists();

        return VariantAvailability::state(
            true,
            (bool) ($v['tracks_inventory'] ?? false),
            ($v['on_hand'] ?? null) !== null ? (int) $v['on_hand'] : null,
            ($v['reserved'] ?? null) !== null ? (int) $v['reserved'] : null,
            $digital,
            $hasReadyFile,
        ) === VariantAvailability::IN_STOCK;
    }
}
