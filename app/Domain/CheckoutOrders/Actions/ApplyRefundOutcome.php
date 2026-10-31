<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\Catalog\Contracts\InventoryAllocator;
use App\Domain\Catalog\Data\InventoryLine;
use App\Domain\Catalog\Data\InventoryMutation;
use App\Domain\CheckoutOrders\Data\RefundResult;
use App\Domain\CheckoutOrders\Support\OrderStates;
use App\Domain\Fulfillment\Actions\RevokeEntitlements;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ApplyRefundOutcome
{
    public function apply(string $refundId, RefundResult $result): void
    {
        DB::transaction(function () use ($refundId, $result): void {
            $refund = DB::table('refunds')->where('id', $refundId)->lockForUpdate()->first();
            if ($refund === null) {
                return;
            }
            $status = $this->string($refund, 'status');
            if (in_array($status, ['succeeded', 'failed'], true)) {
                return;
            }
            $orderId = $this->string($refund, 'order_id');
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null) {
                return;
            }
            $lines = DB::table('order_lines')->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get();
            $now = now();
            $providerId = $result->providerRefundId;
            if ($result->isSucceeded()) {
                $this->commit($refund, $lines);
                $this->project($orderId, $this->int($refund, 'amount'));
                $values = ['status' => 'succeeded', 'updated_at' => $now];
                if (is_string($providerId) && $providerId !== '') {
                    $values['provider_refund_id'] = $providerId;
                }
                DB::table('refunds')->where('id', $refundId)->update($values);
                app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-refund:'.$refundId, [
                    'action' => 'checkout.order.refund_succeeded',
                    'subject_type' => 'order',
                    'subject_id' => $orderId,
                    'actor_type' => 'staff',
                    'actor_id' => $this->string($refund, 'staff_id'),
                    'metadata' => ['version' => 1],
                ]);
                if ($this->string($refund, 'kind') === 'cancellation') {
                    app(CancelOrder::class)->complete($orderId, $this->string($refund, 'reason'));
                }
                // Access follows the money fact in the same transaction:
                // a succeeded refund re-syncs issued entitlements.
                app(RevokeEntitlements::class)->syncForOrder($orderId, $refundId, 'refund:'.$refundId);

                return;
            }
            if ($result->mayReleaseAllocation()) {
                $this->clearHolds($refundId, $lines);
                $values = ['status' => 'failed', 'updated_at' => $now];
                if (is_string($providerId) && $providerId !== '') {
                    $values['provider_refund_id'] = $providerId;
                }
                DB::table('refunds')->where('id', $refundId)->update($values);

                return;
            }
            $values = ['status' => $result->status === 'unknown' ? 'unknown' : 'processing', 'updated_at' => $now];
            if (is_string($providerId) && $providerId !== '') {
                $values['provider_refund_id'] = $providerId;
            }
            DB::table('refunds')->where('id', $refundId)->update($values);
        });
    }

    /** @param  iterable<object>  $lines */
    private function commit(object $refund, iterable $lines): void
    {
        if (! in_array($this->string($refund, 'kind'), ['quantity', 'cancellation'], true)) {
            return;
        }
        $refundId = $this->string($refund, 'id');
        $orderId = $this->string($refund, 'order_id');
        $claimed = [];
        foreach (DB::table('refund_lines')->where('refund_id', $refundId)->orderBy('order_line_id')->get() as $row) {
            $claimed[$this->string($row, 'order_line_id')] = $this->int($row, 'quantity');
        }
        $release = [];
        foreach ($lines as $line) {
            $lineId = $this->string($line, 'id');
            $quantity = $claimed[$lineId] ?? 0;
            if ($quantity < 1) {
                continue;
            }
            DB::table('order_lines')->where('id', $lineId)->update([
                'held_qty' => $this->int($line, 'held_qty') - $quantity,
                'cancelled_qty' => $this->int($line, 'cancelled_qty') + $quantity,
                'refunded_qty' => $this->int($line, 'refunded_qty') + $quantity,
                'eligibility_revision' => $this->int($line, 'eligibility_revision') + 1,
            ]);
            if ($this->string($line, 'type') === 'physical') {
                $variantId = $this->string($line, 'variant_id');
                $reservation = DB::table('stock_reservations')
                    ->where('intent_id', $orderId)
                    ->where('variant_id', $variantId)
                    ->lockForUpdate()
                    ->first();
                if ($reservation !== null) {
                    $release[] = new InventoryLine($variantId, $quantity);
                }
            }
        }
        if ($release === []) {
            return;
        }
        usort($release, fn (InventoryLine $left, InventoryLine $right): int => $left->variantId <=> $right->variantId);
        app(InventoryAllocator::class)->release(new InventoryMutation(
            $orderId,
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'mrktflow:refund-release:'.$refundId)->toString(),
            $release,
        ));
    }

    /** @param  iterable<object>  $lines */
    private function clearHolds(string $refundId, iterable $lines): void
    {
        $claimed = [];
        foreach (DB::table('refund_lines')->where('refund_id', $refundId)->orderBy('order_line_id')->get() as $row) {
            $claimed[$this->string($row, 'order_line_id')] = $this->int($row, 'quantity');
        }
        foreach ($lines as $line) {
            $lineId = $this->string($line, 'id');
            $quantity = $claimed[$lineId] ?? 0;
            if ($quantity < 1) {
                continue;
            }
            DB::table('order_lines')->where('id', $lineId)->update([
                'held_qty' => $this->int($line, 'held_qty') - $quantity,
            ]);
        }
    }

    private function project(string $orderId, int $thisAmount): void
    {
        $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        $payment = DB::table('payments')->where('order_id', $orderId)->lockForUpdate()->first();
        if ($order === null || $payment === null) {
            return;
        }
        $refunded = (int) DB::table('refunds')->where('order_id', $orderId)->where('status', 'succeeded')->sum('amount') + $thisAmount;
        $captured = $this->int($payment, 'amount');
        $financial = $refunded >= $captured ? 'refunded' : 'partially_refunded';
        OrderStates::assertFinancial($financial);
        DB::table('orders')->where('id', $orderId)->update([
            'financial_status' => $financial,
            'updated_at' => now(),
        ]);
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
