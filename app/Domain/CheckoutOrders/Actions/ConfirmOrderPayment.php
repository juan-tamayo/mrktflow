<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\Catalog\Contracts\InventoryAllocator;
use App\Domain\Catalog\Exceptions\CatalogInsufficientStock;
use App\Domain\CheckoutOrders\Data\PaymentSession;
use App\Domain\CheckoutOrders\Support\OrderStates;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Pricing\Actions\HoldCoupon;
use App\Domain\Pricing\Exceptions\PricingCouponExhausted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class ConfirmOrderPayment
{
    public function confirm(string $attemptId, PaymentSession $session): void
    {
        $sessionId = $session->sessionId;
        $captureId = $session->captureId;
        if (! Str::isUuid($attemptId) || $attemptId !== strtolower($attemptId) || ! $session->isPaid()
            || $sessionId === null || $captureId === null) {
            return;
        }

        DB::transaction(function () use ($attemptId, $sessionId, $captureId): void {
            $attempt = DB::table('payment_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            if ($attempt === null) {
                return;
            }
            $orderId = $this->string($attempt, 'order_id');
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null || $this->bool($order, 'zero_total')) {
                return;
            }
            $status = $this->string($attempt, 'status');
            if ($status === 'failed') {
                return;
            }
            $storedSession = $this->nullable($attempt, 'provider_session_id');
            if ($storedSession !== null && $storedSession !== $sessionId) {
                return;
            }
            if ($this->int($attempt, 'amount') !== $this->int($order, 'grand_total_amount')
                || $this->string($attempt, 'currency') !== $this->string($order, 'currency')) {
                return;
            }
            $account = $this->nullable($attempt, 'provider_account');
            $expectedAccount = config('services.stripe.account');
            $live = $this->bool($attempt, 'live');
            if (! is_string($expectedAccount) || $expectedAccount === '' || $account !== $expectedAccount
                || $live !== (config('services.stripe.live') === true)) {
                return;
            }
            $now = now();
            if ($status !== 'succeeded') {
                $this->recordCapture($order, $attempt, $captureId, $now);
                $shortage = ! $this->restoreAllocations($orderId);
                DB::table('payment_attempts')->where('id', $attemptId)->update([
                    'status' => 'succeeded',
                    'provider' => 'stripe',
                    'provider_session_id' => $sessionId,
                    'updated_at' => $now,
                ]);
                DB::table('orders')->where('id', $orderId)->update(array_merge(OrderStates::paidColumns($order), [
                    'status' => $shortage ? 'on_hold' : 'confirmed',
                    'updated_at' => $now,
                ]));
                if ($shortage) {
                    $this->alertShortage($order);
                }
            }
            $this->emitPaid($order, $attemptId, $captureId);
        });
    }

    private function recordCapture(object $order, object $attempt, string $captureId, mixed $now): void
    {
        try {
            DB::table('payments')->insert([
                'id' => (string) Str::uuid(),
                'order_id' => $this->string($order, 'id'),
                'attempt_id' => $this->string($attempt, 'id'),
                'provider' => 'stripe',
                'provider_capture_id' => $captureId,
                'amount' => $this->int($order, 'grand_total_amount'),
                'currency' => $this->string($order, 'currency'),
                'exponent' => $this->int($order, 'exponent'),
                'captured_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = DB::table('payments')->where('attempt_id', $this->string($attempt, 'id'))->lockForUpdate()->first();
            if ($existing === null || $this->nullable($existing, 'provider_capture_id') !== $captureId) {
                throw new RuntimeException('A different capture is already recorded for this attempt.');
            }
        }
    }

    private function restoreAllocations(string $orderId): bool
    {
        $reservation = DB::table('stock_reservations')->where('intent_id', $orderId)->orderBy('variant_id')->lockForUpdate()->first();
        if ($reservation !== null) {
            $status = $this->string($reservation, 'status');
            if ($status === 'released') {
                try {
                    app(InventoryAllocator::class)->rehold($orderId, $this->operationKey('stock-rehold', $orderId));
                } catch (CatalogInsufficientStock) {
                    return false;
                }
                $status = 'active';
            }
            if ($status === 'active') {
                app(InventoryAllocator::class)->commit($orderId, $this->operationKey('stock-commit', $orderId));
            }
        }
        $coupon = DB::table('coupon_holds')->where('intent_id', $orderId)->lockForUpdate()->first();
        if ($coupon === null) {
            return true;
        }
        $couponStatus = $this->string($coupon, 'status');
        if ($couponStatus === 'released') {
            try {
                app(HoldCoupon::class)->rehold($orderId, $this->operationKey('coupon-rehold', $orderId));
            } catch (PricingCouponExhausted) {
                return false;
            }
            $couponStatus = 'active';
        }
        if ($couponStatus === 'active') {
            app(HoldCoupon::class)->commit($orderId, $this->operationKey('coupon-commit', $orderId));
        }

        return true;
    }

    private function alertShortage(object $order): void
    {
        $orderId = $this->string($order, 'id');
        app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-hold:'.$orderId, [
            'action' => 'checkout.order.on-hold',
            'subject_type' => 'order',
            'subject_id' => $orderId,
            'actor_type' => 'system',
            'metadata' => ['reason_code' => 'late-success-stock'],
        ]);
    }

    private function emitPaid(object $order, string $attemptId, string $captureId): void
    {
        $orderId = $this->string($order, 'id');
        app(RecordEvent::class)->record('checkout.order.paid', 'order:paid:'.$orderId, [
            'order_id' => $orderId,
            'order_number' => $this->string($order, 'number'),
            'attempt_id' => $attemptId,
            'capture_id' => $captureId,
            'amount' => $this->int($order, 'grand_total_amount'),
            'currency' => $this->string($order, 'currency'),
        ]);
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
