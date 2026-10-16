<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\CheckoutOrders\Contracts\PaymentGateway;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\PaymentSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ReconcilePayment
{
    public function reconcile(string $attemptId, ?string $sessionHint = null): void
    {
        if (! Str::isUuid($attemptId) || $attemptId !== strtolower($attemptId)) {
            return;
        }
        $prepared = $this->prepare($attemptId, $sessionHint);
        if ($prepared === null) {
            return;
        }
        $result = app(PaymentGateway::class)->retrieveSession($prepared['intent'], $prepared['session_id']);
        app(ConfirmOrderPayment::class)->confirm($attemptId, $result->session);
        $this->rememberUnknown($attemptId, $result->session);
    }

    /**
     * @return array{intent: PaymentIntent, session_id: ?string}|null
     */
    private function prepare(string $attemptId, ?string $sessionHint): ?array
    {
        return DB::transaction(function () use ($attemptId, $sessionHint): ?array {
            $attempt = DB::table('payment_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            if ($attempt === null) {
                return null;
            }
            $status = $this->string($attempt, 'status');
            if (in_array($status, ['succeeded', 'failed'], true)) {
                return null;
            }
            $order = DB::table('orders')->where('id', $this->string($attempt, 'order_id'))->lockForUpdate()->first();
            if ($order === null || $this->bool($order, 'zero_total')) {
                return null;
            }
            $account = $this->nullable($attempt, 'provider_account');
            if ($account === null) {
                $configured = config('services.stripe.account');
                $account = is_string($configured) && $configured !== '' ? $configured : null;
            }
            if ($account === null) {
                return null;
            }
            $sessionId = $this->nullable($attempt, 'provider_session_id');
            if ($sessionId === null && is_string($sessionHint) && $sessionHint !== '') {
                PaymentIntent::assertReference($sessionHint);
                $sessionId = $sessionHint;
                DB::table('payment_attempts')->where('id', $attemptId)->update([
                    'provider' => 'stripe',
                    'provider_account' => $account,
                    'provider_session_id' => $sessionId,
                    'updated_at' => now(),
                ]);
            }
            try {
                $intent = new PaymentIntent(
                    $attemptId,
                    $this->int($order, 'grand_total_amount'),
                    $this->string($order, 'currency'),
                    $account,
                    $this->bool($attempt, 'live'),
                );
            } catch (InvalidArgumentException) {
                return null;
            }

            return ['intent' => $intent, 'session_id' => $sessionId];
        });
    }

    private function rememberUnknown(string $attemptId, PaymentSession $session): void
    {
        if ($session->isPaid() || $session->state !== 'unknown') {
            return;
        }
        DB::transaction(function () use ($attemptId): void {
            $attempt = DB::table('payment_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            if ($attempt === null) {
                return;
            }
            $status = $this->string($attempt, 'status');
            if ($status === 'succeeded') {
                return;
            }
            if (in_array($status, ['created', 'awaiting_payment'], true) && $this->nullable($attempt, 'provider_session_id') === null) {
                DB::table('payment_attempts')->where('id', $attemptId)->update([
                    'status' => 'unknown',
                    'updated_at' => now(),
                ]);
            }
        });
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function nullable(object $row, string $key): ?string
    {
        $value = $this->string($row, $key);

        return $value === '' ? null : $value;
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function bool(object $row, string $key): bool
    {
        $value = get_object_vars($row)[$key] ?? null;

        return $value === true || $value === 1 || $value === '1';
    }
}
