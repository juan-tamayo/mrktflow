<?php

declare(strict_types=1);

namespace App\Domain\ShoppingTools\Actions;

use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Platform\Actions\UpdateDeliveryPreference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Email verification for watch subscriptions (SHOP-03).
 *
 * The challenge idiom is the platform's own, keyed to a delivery
 * contact instead of a customer account so a guest can verify: only
 * the SHA-256 digest of a 256-bit token is stored, the row is bound
 * to the contact's email digest, it expires in 24 hours, and issuing
 * a fresh challenge revokes the previous one. Consuming is the
 * double opt-in itself — one transaction marks the contact verified
 * through UpdateDeliveryPreference (the sole consent writer), grants
 * the pending watch purposes with `double_opt_in` provenance,
 * activates the pending subscriptions and snapshots price baselines.
 * Consumption nulls the digest, so a replayed token finds nothing
 * and fails closed with the same 404 as an unknown token; nothing is
 * sent from here — issuance records a queued mail intent and hands
 * the raw token back to the caller layer, exactly like guest
 * download access.
 */
final class VerifyWatchEmail
{
    /** @var array<string, string> */
    private const PURPOSE_BY_TYPE = ['stock' => 'stock_alert', 'price' => 'price_alert'];

    /**
     * @return array{token: string, verification_id: string, expires_at: CarbonImmutable}
     */
    public function issue(string $contactId): array
    {
        return DB::transaction(function () use ($contactId): array {
            $contact = DB::table('delivery_contacts')->where('id', $contactId)->lockForUpdate()->first();
            abort_unless($contact !== null, 404);
            DB::table('watch_verifications')->where('contact_id', $contactId)
                ->whereNull('consumed_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'token_digest' => null, 'updated_at' => now()]);
            $id = (string) Str::uuid();
            $token = bin2hex(random_bytes(32));
            $expiresAt = CarbonImmutable::now()->addDay();
            $email = (string) $contact->email;
            DB::table('watch_verifications')->insert([
                'id' => $id,
                'contact_id' => $contactId,
                'token_digest' => hash('sha256', $token),
                'email_digest' => hash('sha256', $email),
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $intentId = (string) Str::uuid();
            DB::table('watch_verification_intents')->insert([
                'id' => $intentId,
                'contact_id' => $contactId,
                'verification_id' => $id,
                'template' => 'watch-verification',
                'recipient' => $email,
                'intent_key' => 'watch-verification:'.$id,
                'kind' => 'request',
                'status' => 'queued',
                'created_at' => now(),
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'watch:verification:'.$id, [
                'action' => 'shopping.watch_verification_requested',
                'subject_type' => 'delivery_contact',
                'subject_id' => $contactId,
                'actor_type' => 'guest',
                'actor_id' => null,
                'metadata' => ['verification_id' => $id],
            ]);

            return ['token' => $token, 'verification_id' => $id, 'expires_at' => $expiresAt];
        });
    }

    /**
     * Consumes one token atomically and returns the verified contact
     * id. Every failure is the same 404 so existence never leaks.
     */
    public function consume(#[\SensitiveParameter] string $token): string
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token) === 1, 404);
        $digest = hash('sha256', $token);

        return DB::transaction(function () use ($digest): string {
            $contactId = DB::table('watch_verifications')->where('token_digest', $digest)->value('contact_id');
            abort_unless(is_string($contactId), 404);
            // Lock the contact first, then its verification rows — the
            // same order issuance and the preference writer use.
            $contact = DB::table('delivery_contacts')->where('id', $contactId)->lockForUpdate()->first();
            $verification = DB::table('watch_verifications')->where('token_digest', $digest)
                ->where('contact_id', $contactId)->lockForUpdate()->first();
            abort_unless($contact !== null && $verification !== null
                && $verification->consumed_at === null && $verification->revoked_at === null
                && CarbonImmutable::parse($verification->expires_at)->isFuture()
                && hash_equals((string) $verification->email_digest, hash('sha256', (string) $contact->email)), 404);
            DB::table('watch_verifications')->where('id', $verification->id)
                ->update(['consumed_at' => now(), 'token_digest' => null, 'updated_at' => now()]);
            DB::table('watch_verifications')->where('contact_id', $contactId)
                ->whereNull('consumed_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'token_digest' => null, 'updated_at' => now()]);

            $preferences = app(UpdateDeliveryPreference::class);
            $preferences->markVerified($contactId, 'watch_verification', 'guest');
            $pending = DB::table('watch_subscriptions')
                ->where('contact_id', $contactId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();
            foreach ($pending as $subscription) {
                $type = (string) $subscription->alert_type;
                $preferences->grant($contactId, self::PURPOSE_BY_TYPE[$type], 'double_opt_in', 'watch_verification', 'guest');
                $update = ['status' => 'active', 'updated_at' => now()];
                if ($type === 'price') {
                    $variant = DB::table('product_variants')->where('id', $subscription->variant_id)->first();
                    if ($variant !== null) {
                        $update['baseline_amount'] = $variant->price_amount;
                        $update['baseline_currency'] = $variant->currency;
                        $update['baseline_exponent'] = $variant->exponent;
                    }
                }
                DB::table('watch_subscriptions')->where('id', $subscription->id)->update($update);
            }
            app(RecordEvent::class)->record('platform.audit.recorded', 'watch:verified:'.$verification->id, [
                'action' => 'shopping.watch_verified',
                'subject_type' => 'delivery_contact',
                'subject_id' => $contactId,
                'actor_type' => 'guest',
                'actor_id' => null,
            ]);

            return $contactId;
        });
    }

    /**
     * Whether a live (unconsumed, unrevoked, unexpired) challenge
     * exists for the token — the read the confirmation form needs
     * without consuming anything.
     */
    public function pending(#[\SensitiveParameter] string $token): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return false;
        }
        $verification = DB::table('watch_verifications')
            ->where('token_digest', hash('sha256', $token))
            ->first();

        return $verification !== null
            && $verification->consumed_at === null
            && $verification->revoked_at === null
            && CarbonImmutable::parse($verification->expires_at)->isFuture();
    }
}
