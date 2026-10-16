<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\Catalog\Contracts\InventoryAllocator;
use App\Domain\Catalog\Data\InventoryLine;
use App\Domain\Catalog\Data\InventoryMutation;
use App\Domain\CheckoutOrders\Contracts\PaymentGateway;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\PaymentSession;
use App\Domain\Pricing\Actions\HoldCoupon;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final class ExpireCheckout
{
    public function expire(string $attemptId): void
    {
        if (! Str::isUuid($attemptId) || $attemptId !== strtolower($attemptId)) {
            return;
        }
        $prepared = $this->prepare($attemptId);
        if ($prepared === null) {
            return;
        }
        $session = PaymentSession::unknown();
        if ($prepared['session_id'] !== null) {
            $session = app(PaymentGateway::class)->retrieveSession($prepared['intent'], $prepared['session_id'])->session;
            if ($session->state === 'open') {
                $session = app(PaymentGateway::class)->expireSession($prepared['intent'], $prepared['session_id']);
            }
        }
        if ($session->isPaid()) {
            app(ConfirmOrderPayment::class)->confirm($attemptId, $session);

            return;
        }
        if ($prepared['session_id'] === null || $session->mayReleaseReservation()) {
            $this->release($attemptId);
        }
    }

    /**
     * @return array{intent: PaymentIntent, session_id: ?string}|null
     */
    private function prepare(string $attemptId): ?array
    {
        return DB::transaction(function () use ($attemptId): ?array {
            $attempt = DB::table('payment_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            if ($attempt === null) {
                return null;
            }
            $status = $this->string($attempt, 'status');
            if (! in_array($status, ['created', 'awaiting_payment'], true)) {
                return null;
            }
            $expires = new DateTimeImmutable($this->string($attempt, 'expires_at'), new DateTimeZone('UTC'));
            if (now()->utc() < $expires) {
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
            $sessionId = $this->nullable($attempt, 'provider_session_id');
            if ($sessionId !== null && $account === null) {
                return null;
            }
            try {
                $intent = new PaymentIntent(
                    $attemptId,
                    $this->int($order, 'grand_total_amount'),
                    $this->string($order, 'currency'),
                    $account ?? 'acct_missing',
                    $this->bool($attempt, 'live'),
                );
            } catch (InvalidArgumentException) {
                return null;
            }

            return ['intent' => $intent, 'session_id' => $sessionId];
        });
    }

    private function release(string $attemptId): void
    {
        DB::transaction(function () use ($attemptId): void {
            $attempt = DB::table('payment_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            if ($attempt === null) {
                return;
            }
            $status = $this->string($attempt, 'status');
            if (in_array($status, ['succeeded', 'failed', 'expired'], true)) {
                return;
            }
            $orderId = $this->string($attempt, 'order_id');
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null || $this->string($order, 'status') !== 'pending') {
                return;
            }
            $now = now();
            DB::table('payment_attempts')->where('id', $attemptId)->update([
                'status' => 'expired',
                'updated_at' => $now,
            ]);
            $lines = [];
            foreach (DB::table('stock_reservations')->where('intent_id', $orderId)->orderBy('variant_id')->lockForUpdate()->get() as $row) {
                $remaining = $this->int($row, 'remaining_qty');
                if ($remaining > 0 && $this->string($row, 'status') === 'active') {
                    $lines[] = new InventoryLine($this->string($row, 'variant_id'), $remaining);
                }
            }
            if ($lines !== []) {
                app(InventoryAllocator::class)->release(new InventoryMutation(
                    $orderId,
                    $this->operationKey('stock-release', $orderId),
                    $lines,
                ));
            }
            if (DB::table('coupon_holds')->where('intent_id', $orderId)->where('status', 'active')->lockForUpdate()->exists()) {
                app(HoldCoupon::class)->release($orderId, $this->operationKey('coupon-release', $orderId));
            }
        });
    }

    private function operationKey(string $name, string $orderId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'mrktflow:'.$name.':'.$orderId)->toString();
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
