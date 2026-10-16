<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\CheckoutOrders\Contracts\PaymentGateway;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\RefundIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ReconcileRefund
{
    public function reconcile(string $refundId): void
    {
        if (! Str::isUuid($refundId) || $refundId !== strtolower($refundId)) {
            return;
        }
        $prepared = $this->prepare($refundId);
        if ($prepared === null) {
            return;
        }
        $result = app(PaymentGateway::class)->retrieveRefund($prepared['intent'], $prepared['provider_refund_id']);
        app(ApplyRefundOutcome::class)->apply($refundId, $result);
    }

    /**
     * @return array{intent: RefundIntent, provider_refund_id: ?string}|null
     */
    private function prepare(string $refundId): ?array
    {
        return DB::transaction(function () use ($refundId): ?array {
            $refund = DB::table('refunds')->where('id', $refundId)->lockForUpdate()->first();
            if ($refund === null) {
                return null;
            }
            $status = $this->string($refund, 'status');
            if (in_array($status, ['succeeded', 'failed'], true)) {
                return null;
            }
            $payment = DB::table('payments')->where('id', $this->string($refund, 'payment_id'))->lockForUpdate()->first();
            $attempt = $payment === null ? null : DB::table('payment_attempts')->where('id', $this->string($payment, 'attempt_id'))->lockForUpdate()->first();
            if ($payment === null || $attempt === null) {
                return null;
            }
            $account = $this->nullable($attempt, 'provider_account');
            if ($account === null) {
                return null;
            }
            try {
                $intent = new RefundIntent(
                    $refundId,
                    new PaymentIntent(
                        $this->string($attempt, 'id'),
                        $this->int($payment, 'amount'),
                        $this->string($payment, 'currency'),
                        $account,
                        $this->bool($attempt, 'live'),
                    ),
                    $this->string($refund, 'capture_id'),
                    $this->int($refund, 'amount'),
                );
            } catch (InvalidArgumentException) {
                return null;
            }

            return [
                'intent' => $intent,
                'provider_refund_id' => $this->nullable($refund, 'provider_refund_id'),
            ];
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
