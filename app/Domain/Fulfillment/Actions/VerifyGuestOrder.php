<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\CheckoutOrders\Actions\RecordOrderEvent;
use App\Domain\Platform\Actions\RecordEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guest download access for one guest order (FUL-04, ID-05, ORDER-07).
 *
 * A guest proves ownership of the email on the order, never of an account:
 * issuance stores only a SHA-256 digest of a 256-bit token bound to that
 * order and email, expires in 24 hours, and revokes every earlier token for
 * the order. Consumption is a separate explicit step (the confirmation
 * POST in the controller); merely presenting or fetching the link never
 * consumes anything here. A token can never verify an account-owned order,
 * and claiming an order into an account kills its outstanding tokens.
 */
final class VerifyGuestOrder
{
    /**
     * @return array{token: string, verification_id: string, expires_at: CarbonImmutable}|null
     */
    public function requestAccess(string $orderId, string $email): ?array
    {
        if (! Str::isUuid($orderId) || $orderId !== strtolower($orderId)) {
            return null;
        }
        $email = mb_strtolower(trim($email), 'UTF-8');
        if ($email === '' || strlen($email) > 254) {
            return null;
        }

        return DB::transaction(function () use ($orderId, $email): ?array {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null || $this->nullableString($order, 'customer_id') !== null
                || $this->string($order, 'email') !== $email) {
                return null;
            }
            DB::table('guest_order_verifications')->where('order_id', $orderId)
                ->whereNull('consumed_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'token_digest' => null, 'updated_at' => now()]);
            $id = (string) Str::uuid();
            $token = bin2hex(random_bytes(32));
            $expiresAt = CarbonImmutable::now()->addDay();
            DB::table('guest_order_verifications')->insert([
                'id' => $id,
                'order_id' => $orderId,
                'token_digest' => hash('sha256', $token),
                'email_digest' => hash('sha256', $email),
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $intentId = (string) Str::uuid();
            DB::table('download_access_intents')->insert([
                'id' => $intentId,
                'order_id' => $orderId,
                'verification_id' => $id,
                'template' => 'download-access',
                'recipient' => $email,
                'intent_key' => 'download-access:'.$id,
                'kind' => 'request',
                'status' => 'queued',
                'created_at' => now(),
            ]);
            app(RecordOrderEvent::class)->record($orderId, 'download_access_requested', [
                'intent_id' => $intentId,
                'verification_id' => $id,
                'template' => 'download-access',
                'number' => $this->string($order, 'number'),
            ], $id);

            return ['token' => $token, 'verification_id' => $id, 'expires_at' => $expiresAt];
        });
    }

    /**
     * Consumes one token atomically and returns the order it grants scoped
     * access to. Every failure is the same 404 so existence never leaks.
     */
    public function consume(#[\SensitiveParameter] string $token): string
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token) === 1, 404);
        $digest = hash('sha256', $token);

        return DB::transaction(function () use ($digest): string {
            $orderId = DB::table('guest_order_verifications')->where('token_digest', $digest)->value('order_id');
            abort_unless(is_string($orderId), 404);
            // Lock the order first, then its verification rows — the same
            // order issuance and claiming use.
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            $verification = DB::table('guest_order_verifications')->where('token_digest', $digest)
                ->where('order_id', $orderId)->lockForUpdate()->first();
            abort_unless($order !== null && $verification !== null
                && $this->nullableString($order, 'customer_id') === null
                && $verification->consumed_at === null && $verification->revoked_at === null
                && CarbonImmutable::parse($verification->expires_at)->isFuture()
                && hash_equals($verification->email_digest, hash('sha256', $this->string($order, 'email'))), 404);
            DB::table('guest_order_verifications')->where('id', $verification->id)
                ->update(['consumed_at' => now(), 'token_digest' => null, 'updated_at' => now()]);
            DB::table('guest_order_verifications')->where('order_id', $orderId)
                ->whereNull('consumed_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'token_digest' => null, 'updated_at' => now()]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'guest-order:verified:'.$verification->id, [
                'action' => 'fulfillment.guest_order_verified', 'subject_type' => 'order', 'subject_id' => $orderId,
                'actor_type' => 'guest', 'actor_id' => null,
            ]);

            return $orderId;
        });
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableString(object $row, string $key): ?string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
