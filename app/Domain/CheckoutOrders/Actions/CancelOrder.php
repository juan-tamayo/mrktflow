<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\Catalog\Contracts\InventoryAllocator;
use App\Domain\Catalog\Data\InventoryLine;
use App\Domain\Catalog\Data\InventoryMutation;
use App\Domain\CheckoutOrders\Contracts\PaymentGateway;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\RefundIntent;
use App\Domain\CheckoutOrders\Exceptions\CheckoutIdempotencyConflict;
use App\Domain\CheckoutOrders\Support\RefundAllocator;
use App\Domain\Fulfillment\Actions\RevokeEntitlements;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CancelOrder
{
    public const OPERATION = 'order-cancel';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function cancel(StaffAccount $actor, string $orderId, array $input, string $idempotencyKey): array
    {
        if (! $actor->hasPermission('orders.manage')) {
            throw new AuthorizationException;
        }
        if (! Str::isUuid($orderId) || $orderId !== strtolower($orderId)
            || ! Str::isUuid($idempotencyKey) || $idempotencyKey !== strtolower($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Send a stable cancellation key.']);
        }
        $prepared = $this->claim($actor, $orderId, $input, $idempotencyKey);
        if ($prepared['replay']) {
            return $prepared['view'];
        }
        if ($prepared['intent'] !== null) {
            $result = app(PaymentGateway::class)->refund($prepared['intent']);
            app(ApplyRefundOutcome::class)->apply($prepared['refund_id'] ?? '', $result);
        } else {
            $this->complete($orderId, $prepared['reason']);
        }

        return $this->view($orderId);
    }

    public function complete(string $orderId, string $reason): void
    {
        DB::transaction(function () use ($orderId, $reason): void {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null) {
                return;
            }
            $now = now();
            if ($this->string($order, 'status') !== 'cancelled') {
                $this->releaseRemaining($order);
                $download = $this->string($order, 'download_status');
                $values = [
                    'status' => 'cancelled',
                    'updated_at' => $now,
                ];
                if (in_array($download, ['entitled', 'locked'], true)) {
                    $values['download_status'] = 'revoked';
                }
                DB::table('orders')->where('id', $orderId)->update($values);
            }
            app(RecordEvent::class)->record('checkout.order.cancelled', 'order:cancelled:'.$orderId, [
                'order_id' => $orderId,
                'order_number' => $this->string($order, 'number'),
                'reason' => $reason,
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-cancel:'.$orderId, [
                'action' => 'checkout.order.cancelled',
                'subject_type' => 'order',
                'subject_id' => $orderId,
                'actor_type' => 'staff',
                'metadata' => ['version' => 1],
            ]);
            // Cancellations that reach completion without a refund
            // outcome (zero-total, nothing left to refund) still revoke
            // issued entitlements in the same transaction.
            app(RevokeEntitlements::class)->syncForOrder($orderId, null, 'order-cancel');
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{replay: true, view: array<string, mixed>}|array{replay: false, intent: ?RefundIntent, refund_id: ?string, reason: string}
     */
    private function claim(StaffAccount $actor, string $orderId, array $input, string $key): array
    {
        return DB::transaction(function () use ($actor, $orderId, $input, $key): array {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new NotFoundHttpException;
            }
            $reason = is_string($input['reason'] ?? null) ? trim((string) $input['reason']) : '';
            if ($reason === '' || mb_strlen($reason) > 500) {
                throw ValidationException::withMessages(['reason' => 'Enter a cancellation reason of at most 500 characters.']);
            }
            $hash = hash('sha256', json_encode([
                'order_id' => $orderId,
                'reason' => $reason,
            ], JSON_THROW_ON_ERROR));
            $claim = $this->idempotency($actor, $key, $hash);
            if ($claim['replay']) {
                $resource = $this->nullableObject($claim, 'resource_id');
                if ($resource === null) {
                    throw new CheckoutIdempotencyConflict;
                }

                return ['replay' => true, 'view' => $this->view($orderId)];
            }
            if ($this->string($order, 'status') === 'cancelled') {
                DB::table('idempotency_records')->where('id', $claim['id'])->update([
                    'status' => 'completed',
                    'resource_id' => $orderId,
                    'updated_at' => now(),
                ]);

                return ['replay' => true, 'view' => $this->view($orderId)];
            }
            if (! in_array($this->string($order, 'status'), ['confirmed', 'on_hold', 'completed'], true)) {
                throw ValidationException::withMessages(['order' => 'This order cannot be cancelled.']);
            }
            $lines = array_values(DB::table('order_lines')->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get()->all());
            foreach ($lines as $line) {
                if ($this->int($line, 'shipped_qty') > 0) {
                    throw ValidationException::withMessages(['order' => 'Shipped quantities require the return path.']);
                }
                if ($this->int($line, 'held_qty') > 0) {
                    throw ValidationException::withMessages(['order' => 'Wait for the open refund to resolve before cancelling.']);
                }
            }
            $open = DB::table('refunds')->where('order_id', $orderId)
                ->whereIn('status', ['processing', 'unknown'])
                ->lockForUpdate()
                ->exists();
            if ($open) {
                throw ValidationException::withMessages(['order' => 'Wait for the open refund to resolve before cancelling.']);
            }
            $existingCancel = DB::table('refunds')->where('order_id', $orderId)->where('kind', 'cancellation')
                ->whereIn('status', ['processing', 'unknown', 'succeeded'])
                ->lockForUpdate()
                ->first();
            if ($existingCancel !== null) {
                DB::table('idempotency_records')->where('id', $claim['id'])->update([
                    'status' => 'completed',
                    'resource_id' => $orderId,
                    'updated_at' => now(),
                ]);

                return ['replay' => true, 'view' => $this->view($orderId)];
            }
            $now = now();
            if ($this->bool($order, 'zero_total')) {
                DB::table('idempotency_records')->where('id', $claim['id'])->update([
                    'status' => 'completed',
                    'resource_id' => $orderId,
                    'updated_at' => $now,
                ]);

                return ['replay' => false, 'intent' => null, 'refund_id' => null, 'reason' => $reason];
            }
            $payment = DB::table('payments')->where('order_id', $orderId)->lockForUpdate()->first();
            $attempt = $payment === null ? null : DB::table('payment_attempts')->where('id', $this->string($payment, 'attempt_id'))->lockForUpdate()->first();
            $allocated = $this->allocate($order, $payment, $lines);
            if ($allocated === null) {
                DB::table('idempotency_records')->where('id', $claim['id'])->update([
                    'status' => 'completed',
                    'resource_id' => $orderId,
                    'updated_at' => $now,
                ]);

                return ['replay' => false, 'intent' => null, 'refund_id' => null, 'reason' => $reason];
            }
            if ($payment === null || $attempt === null || $this->string($payment, 'provider') !== 'stripe') {
                throw ValidationException::withMessages(['order' => 'This order has no captured payment to resolve.']);
            }
            $refundId = (string) Str::uuid();
            DB::table('refunds')->insert([
                'id' => $refundId,
                'order_id' => $orderId,
                'payment_id' => $this->string($payment, 'id'),
                'staff_id' => $actor->id,
                'amount' => $allocated['amount'],
                'shipping_amount' => $allocated['shipping_amount'],
                'currency' => $this->string($order, 'currency'),
                'exponent' => $this->int($order, 'exponent'),
                'kind' => 'cancellation',
                'status' => 'processing',
                'reason' => $reason,
                'capture_id' => $this->string($payment, 'provider_capture_id'),
                'provider_refund_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($allocated['lines'] as $line) {
                DB::table('refund_lines')->insert([
                    'id' => (string) Str::uuid(),
                    'refund_id' => $refundId,
                    'order_line_id' => $line['id'],
                    'quantity' => $line['quantity'],
                    'amount' => $line['amount'],
                ]);
                DB::table('order_lines')->where('id', $line['id'])->increment('held_qty', $line['quantity']);
            }
            DB::table('idempotency_records')->where('id', $claim['id'])->update([
                'status' => 'completed',
                'resource_id' => $orderId,
                'updated_at' => $now,
            ]);
            $account = $this->nullable($attempt, 'provider_account');
            if ($account === null) {
                throw ValidationException::withMessages(['order' => 'This order has no captured payment to resolve.']);
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
                    $this->string($payment, 'provider_capture_id'),
                    $allocated['amount'],
                );
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['amount' => 'This cancellation refund is not refundable.']);
            }

            return ['replay' => false, 'intent' => $intent, 'refund_id' => $refundId, 'reason' => $reason];
        });
    }

    /**
     * @param  list<object>  $lines
     * @return array{amount: int, shipping_amount: int, lines: list<array{id: string, quantity: int, amount: int}>}|null
     */
    private function allocate(object $order, ?object $payment, array $lines): ?array
    {
        if ($payment === null) {
            return null;
        }
        $allocator = new RefundAllocator;
        $openAmount = (int) DB::table('refunds')->where('order_id', $this->string($order, 'id'))
            ->whereIn('status', ['processing', 'unknown', 'succeeded'])
            ->lockForUpdate()
            ->sum('amount');
        $remainingMoney = $this->int($payment, 'amount') - $openAmount;
        if ($remainingMoney < 1) {
            return null;
        }
        $shippingTax = $this->int($order, 'tax_amount');
        foreach ($lines as $line) {
            $shippingTax -= $this->int($line, 'tax_amount');
        }
        $openShipping = (int) DB::table('refunds')->where('order_id', $this->string($order, 'id'))
            ->whereIn('status', ['processing', 'unknown', 'succeeded'])
            ->sum('shipping_amount');
        $remainingShipping = max(0, $this->int($order, 'shipping_amount') + max(0, $shippingTax) - $openShipping);
        $claimed = [];
        $merchandise = 0;
        foreach ($lines as $line) {
            $available = $this->int($line, 'quantity') - $this->int($line, 'shipped_qty')
                - $this->int($line, 'cancelled_qty') - $this->int($line, 'held_qty');
            if ($available < 1) {
                continue;
            }
            $consumed = $this->int($line, 'cancelled_qty') + $this->int($line, 'held_qty');
            $lineAmount = $allocator->slice($allocator->unitAmounts($this->int($line, 'total_amount'), $this->int($line, 'quantity')), $consumed, $available)
                + $allocator->slice($allocator->unitAmounts($this->int($line, 'tax_amount'), $this->int($line, 'quantity')), $consumed, $available);
            $claimed[] = ['id' => $this->string($line, 'id'), 'quantity' => $available, 'amount' => $lineAmount];
            $merchandise += $lineAmount;
        }
        $shipping = min($remainingShipping, max(0, $remainingMoney - $merchandise));
        $total = $merchandise + $shipping;
        if ($total < 1 || $total > $remainingMoney) {
            throw ValidationException::withMessages(['amount' => 'Cancellation refund exceeds remaining captured money.']);
        }

        return ['amount' => $total, 'shipping_amount' => $shipping, 'lines' => $claimed];
    }

    private function releaseRemaining(object $order): void
    {
        $orderId = $this->string($order, 'id');
        $release = [];
        foreach (DB::table('order_lines')->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get() as $line) {
            $remaining = $this->int($line, 'quantity') - $this->int($line, 'shipped_qty')
                - $this->int($line, 'cancelled_qty') - $this->int($line, 'held_qty');
            if ($remaining < 1) {
                continue;
            }
            DB::table('order_lines')->where('id', $this->string($line, 'id'))->update([
                'cancelled_qty' => $this->int($line, 'cancelled_qty') + $remaining,
                'eligibility_revision' => $this->int($line, 'eligibility_revision') + 1,
            ]);
            if ($this->string($line, 'type') === 'physical') {
                $release[] = new InventoryLine($this->string($line, 'variant_id'), $remaining);
            }
        }
        if ($release === []) {
            foreach (DB::table('order_lines')->where('order_id', $orderId)->get() as $line) {
                if ($this->int($line, 'eligibility_revision') < 2) {
                    DB::table('order_lines')->where('id', $this->string($line, 'id'))->update([
                        'eligibility_revision' => $this->int($line, 'eligibility_revision') + 1,
                    ]);
                }
            }

            return;
        }
        usort($release, fn (InventoryLine $left, InventoryLine $right): int => $left->variantId <=> $right->variantId);
        app(InventoryAllocator::class)->release(new InventoryMutation(
            $orderId,
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'mrktflow:cancel-release:'.$orderId)->toString(),
            $release,
        ));
    }

    /**
     * @return array{id: string, replay: bool, resource_id: ?string}
     */
    private function idempotency(StaffAccount $actor, string $key, string $hash): array
    {
        $scope = 'staff:'.$actor->id;
        try {
            $id = (string) Str::uuid();
            DB::table('idempotency_records')->insert([
                'id' => $id,
                'principal_scope' => $scope,
                'operation' => self::OPERATION,
                'idempotency_key' => $key,
                'payload_hash' => $hash,
                'status' => 'processing',
                'resource_id' => null,
                'attempt_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['id' => $id, 'replay' => false, 'resource_id' => null];
        } catch (UniqueConstraintViolationException) {
            $existing = DB::table('idempotency_records')
                ->where('principal_scope', $scope)
                ->where('operation', self::OPERATION)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($existing === null) {
                throw new CheckoutIdempotencyConflict;
            }
            if ($this->string($existing, 'payload_hash') !== $hash) {
                throw new CheckoutIdempotencyConflict;
            }

            return [
                'id' => $this->string($existing, 'id'),
                'replay' => true,
                'resource_id' => $this->nullable($existing, 'resource_id'),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function view(string $orderId): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first();
        if ($order === null) {
            throw new NotFoundHttpException;
        }

        return [
            'id' => $this->string($order, 'id'),
            'status' => $this->string($order, 'status'),
            'financial_status' => $this->string($order, 'financial_status'),
        ];
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

    /** @param  array{resource_id: ?string}  $claim */
    private function nullableObject(array $claim, string $key): ?string
    {
        $value = $claim[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
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
