<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\CheckoutOrders\Data\DisputeObservation;
use App\Domain\CheckoutOrders\Data\ExternalRefundObservation;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\RefundIntent;
use App\Domain\CheckoutOrders\Data\RefundResult;
use App\Domain\CheckoutOrders\Support\OrderStates;
use App\Domain\Fulfillment\Actions\RevokeEntitlements;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reconciles provider-originated money facts (refunds created outside
 * MRKTFlow, dispute outcomes) into local history. Provider facts are
 * immutable: staff rows are only ever reconciled, never rewritten, and a
 * provider fact consumes refundable bounds exactly like a staff refund.
 */
final class ReconcileExternalAdjustment
{
    public function reconcileRefund(ExternalRefundObservation $observation, string $sourceEventId): void
    {
        DB::transaction(function () use ($observation, $sourceEventId): void {
            $payment = DB::table('payments')->where('provider_capture_id', $observation->captureReference)->lockForUpdate()->first();
            if ($payment === null) {
                return;
            }
            $order = DB::table('orders')->where('id', $this->string($payment, 'order_id'))->lockForUpdate()->first();
            if ($order === null || $observation->currency !== $this->string($payment, 'currency')
                || $observation->amountMinor > $this->int($payment, 'amount')) {
                return;
            }
            $attempt = DB::table('payment_attempts')->where('id', $this->string($payment, 'attempt_id'))->lockForUpdate()->first();
            if ($attempt === null) {
                return;
            }
            $orderId = $this->string($order, 'id');
            $known = DB::table('refunds')->where('provider_refund_id', $observation->providerRefundId)->lockForUpdate()->first();
            if ($known !== null) {
                if ($this->string($known, 'order_id') !== $orderId) {
                    return;
                }
                if ($this->string($known, 'origin') === 'staff') {
                    $this->applyStaffObservation($known, $payment, $attempt, $observation);
                } else {
                    $this->applyExternalTransition($known, $order, $observation, $sourceEventId);
                }

                return;
            }
            if ($observation->localRefundId !== null) {
                $local = DB::table('refunds')->where('id', $observation->localRefundId)
                    ->where('order_id', $orderId)->lockForUpdate()->first();
                if ($local !== null) {
                    $bound = $this->nullable($local, 'provider_refund_id');
                    if ($bound !== null || $this->string($local, 'origin') !== 'staff') {
                        return;
                    }
                    $this->applyStaffObservation($local, $payment, $attempt, $observation);

                    return;
                }
            }
            $refundId = (string) Str::uuid();
            $now = now();
            $status = $observation->localStatus();
            DB::table('refunds')->insert([
                'id' => $refundId,
                'order_id' => $orderId,
                'payment_id' => $this->string($payment, 'id'),
                'staff_id' => null,
                'amount' => $observation->amountMinor,
                'shipping_amount' => 0,
                'currency' => $this->string($order, 'currency'),
                'exponent' => $this->int($order, 'exponent'),
                'kind' => 'external',
                'origin' => 'provider',
                'status' => $status,
                'reason' => 'Refund created outside MRKTFlow'
                    .($observation->providerReason !== null ? ' ('.$observation->providerReason.')' : ''),
                'capture_id' => $this->string($payment, 'provider_capture_id'),
                'provider_refund_id' => $observation->providerRefundId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($status === 'succeeded') {
                $this->refundSucceededEffects($order, $refundId, $observation->amountMinor, $sourceEventId);
            }
        });
    }

    public function reconcileDispute(DisputeObservation $observation, string $sourceEventId): void
    {
        DB::transaction(function () use ($observation, $sourceEventId): void {
            $payment = DB::table('payments')->where('provider_capture_id', $observation->captureReference)->lockForUpdate()->first();
            if ($payment === null) {
                return;
            }
            $order = DB::table('orders')->where('id', $this->string($payment, 'order_id'))->lockForUpdate()->first();
            if ($order === null || $observation->currency !== $this->string($payment, 'currency')
                || $observation->amountMinor > $this->int($payment, 'amount')) {
                return;
            }
            $orderId = $this->string($order, 'id');
            $dispute = DB::table('order_disputes')->where('provider_dispute_id', $observation->providerDisputeId)->lockForUpdate()->first();
            if ($dispute === null) {
                $disputeId = (string) Str::uuid();
                $now = now();
                DB::table('order_disputes')->insert([
                    'id' => $disputeId,
                    'order_id' => $orderId,
                    'payment_id' => $this->string($payment, 'id'),
                    'provider_dispute_id' => $observation->providerDisputeId,
                    'amount' => $observation->amountMinor,
                    'currency' => $this->string($order, 'currency'),
                    'exponent' => $this->int($order, 'exponent'),
                    'status' => $observation->status,
                    'reason' => $observation->reason,
                    'held_order' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $dispute = DB::table('order_disputes')->where('id', $disputeId)->lockForUpdate()->first();
                if ($dispute === null) {
                    return;
                }
                match ($observation->status) {
                    'opened' => $this->disputeOpenedEffects($dispute, $order, $sourceEventId),
                    'won' => $this->disputeWonEffects($dispute, $order, $sourceEventId),
                    'lost' => $this->disputeLostEffects($dispute, $order, $payment, $sourceEventId),
                    default => null,
                };

                return;
            }
            if ($this->string($dispute, 'order_id') !== $orderId
                || in_array($this->string($dispute, 'status'), ['won', 'lost'], true)
                || $this->int($dispute, 'amount') !== $observation->amountMinor
                || $observation->status === 'opened') {
                return;
            }
            DB::table('order_disputes')->where('id', $this->string($dispute, 'id'))->update([
                'status' => $observation->status,
                'updated_at' => now(),
            ]);
            $dispute = DB::table('order_disputes')->where('id', $this->string($dispute, 'id'))->lockForUpdate()->first();
            if ($dispute === null) {
                return;
            }
            if ($observation->status === 'won') {
                $this->disputeWonEffects($dispute, $order, $sourceEventId);
            } else {
                $this->disputeLostEffects($dispute, $order, $payment, $sourceEventId);
            }
        });
    }

    private function applyStaffObservation(object $refund, object $payment, object $attempt, ExternalRefundObservation $observation): void
    {
        if ($this->int($refund, 'amount') !== $observation->amountMinor) {
            return;
        }
        $account = $this->nullable($attempt, 'provider_account');
        if ($account === null) {
            return;
        }
        try {
            $intent = new RefundIntent(
                $this->string($refund, 'id'),
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
            $result = RefundResult::observed($intent, $intent, $observation->providerRefundId, $observation->providerStatus);
        } catch (InvalidArgumentException) {
            return;
        }
        app(ApplyRefundOutcome::class)->apply($this->string($refund, 'id'), $result);
    }

    private function applyExternalTransition(object $refund, object $order, ExternalRefundObservation $observation, string $sourceEventId): void
    {
        $current = $this->string($refund, 'status');
        if (in_array($current, ['succeeded', 'failed'], true)
            || $this->int($refund, 'amount') !== $observation->amountMinor) {
            return;
        }
        $next = $observation->localStatus();
        if ($next === $current) {
            return;
        }
        DB::table('refunds')->where('id', $this->string($refund, 'id'))->update([
            'status' => $next,
            'updated_at' => now(),
        ]);
        if ($next === 'succeeded') {
            $this->refundSucceededEffects($order, $this->string($refund, 'id'), $observation->amountMinor, $sourceEventId);
        }
    }

    private function refundSucceededEffects(object $order, string $refundId, int $amount, string $sourceEventId): void
    {
        $orderId = $this->string($order, 'id');
        $this->project($orderId);
        app(RecordOrderEvent::class)->record($orderId, 'external_refund', [
            'amount' => $amount,
            'currency' => $this->string($order, 'currency'),
            'exponent' => $this->int($order, 'exponent'),
        ], $sourceEventId);
        app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-external-refund:'.$refundId, [
            'action' => 'checkout.order.external_refund_succeeded',
            'subject_type' => 'order',
            'subject_id' => $orderId,
            'actor_type' => 'system',
            'actor_id' => 'provider:stripe',
            'metadata' => ['version' => 1],
        ]);
        app(RevokeEntitlements::class)->syncForOrder($orderId, $sourceEventId, 'provider-event:'.$sourceEventId);
    }

    private function disputeOpenedEffects(object $dispute, object $order, string $sourceEventId): void
    {
        $orderId = $this->string($order, 'id');
        $disputeId = $this->string($dispute, 'id');
        $values = ['dispute_status' => 'open', 'updated_at' => now()];
        if ($this->string($order, 'status') === 'confirmed') {
            $values['status'] = 'on_hold';
            DB::table('order_disputes')->where('id', $disputeId)->update(['held_order' => true, 'updated_at' => now()]);
        }
        if ($this->string($order, 'download_status') === 'entitled') {
            $values['download_status'] = 'suspended';
        }
        DB::table('orders')->where('id', $orderId)->update($values);
        app(RecordOrderEvent::class)->record($orderId, 'dispute_opened', [
            'amount' => $this->int($dispute, 'amount'),
            'currency' => $this->string($order, 'currency'),
            'exponent' => $this->int($order, 'exponent'),
        ], $sourceEventId);
        app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-dispute-opened:'.$disputeId, [
            'action' => 'checkout.order.dispute_opened',
            'subject_type' => 'order',
            'subject_id' => $orderId,
            'actor_type' => 'system',
            'actor_id' => 'provider:stripe',
            'metadata' => ['version' => 1],
        ]);
        app(RevokeEntitlements::class)->syncForOrder($orderId, $sourceEventId, 'provider-event:'.$sourceEventId);
    }

    private function disputeWonEffects(object $dispute, object $order, string $sourceEventId): void
    {
        $orderId = $this->string($order, 'id');
        $disputeId = $this->string($dispute, 'id');
        $values = ['dispute_status' => 'won', 'updated_at' => now()];
        if ($this->bool($dispute, 'held_order') && $this->string($order, 'status') === 'on_hold') {
            $values['status'] = 'confirmed';
        }
        if ($this->string($order, 'download_status') === 'suspended'
            && $this->string($order, 'status') !== 'cancelled'
            && in_array($this->string($order, 'financial_status'), ['paid', 'partially_refunded'], true)) {
            $values['download_status'] = 'entitled';
        }
        DB::table('orders')->where('id', $orderId)->update($values);
        app(RecordOrderEvent::class)->record($orderId, 'dispute_won', [
            'amount' => $this->int($dispute, 'amount'),
            'currency' => $this->string($order, 'currency'),
            'exponent' => $this->int($order, 'exponent'),
        ], $sourceEventId);
        app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-dispute-won:'.$disputeId, [
            'action' => 'checkout.order.dispute_won',
            'subject_type' => 'order',
            'subject_id' => $orderId,
            'actor_type' => 'system',
            'actor_id' => 'provider:stripe',
            'metadata' => ['version' => 1],
        ]);
        app(RevokeEntitlements::class)->syncForOrder($orderId, $sourceEventId, 'provider-event:'.$sourceEventId);
    }

    private function disputeLostEffects(object $dispute, object $order, object $payment, string $sourceEventId): void
    {
        $orderId = $this->string($order, 'id');
        $disputeId = $this->string($dispute, 'id');
        $now = now();
        DB::table('refunds')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $orderId,
            'payment_id' => $this->string($payment, 'id'),
            'staff_id' => null,
            'amount' => $this->int($dispute, 'amount'),
            'shipping_amount' => 0,
            'currency' => $this->string($order, 'currency'),
            'exponent' => $this->int($order, 'exponent'),
            'kind' => 'external',
            'origin' => 'provider',
            'status' => 'succeeded',
            'reason' => 'Dispute lost — provider debit',
            'capture_id' => $this->string($payment, 'provider_capture_id'),
            'provider_refund_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->project($orderId);
        $values = ['dispute_status' => 'lost', 'updated_at' => $now];
        if (in_array($this->string($order, 'download_status'), ['entitled', 'suspended'], true)) {
            $values['download_status'] = 'revoked';
        }
        DB::table('orders')->where('id', $orderId)->update($values);
        app(RecordOrderEvent::class)->record($orderId, 'dispute_lost', [
            'amount' => $this->int($dispute, 'amount'),
            'currency' => $this->string($order, 'currency'),
            'exponent' => $this->int($order, 'exponent'),
        ], $sourceEventId);
        app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-dispute-lost:'.$disputeId, [
            'action' => 'checkout.order.dispute_lost',
            'subject_type' => 'order',
            'subject_id' => $orderId,
            'actor_type' => 'system',
            'actor_id' => 'provider:stripe',
            'metadata' => ['version' => 1],
        ]);
        app(RevokeEntitlements::class)->syncForOrder($orderId, $sourceEventId, 'provider-event:'.$sourceEventId);
    }

    private function project(string $orderId): void
    {
        $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        $payment = DB::table('payments')->where('order_id', $orderId)->lockForUpdate()->first();
        if ($order === null || $payment === null) {
            return;
        }
        if (! in_array($this->string($order, 'financial_status'), ['paid', 'partially_refunded', 'refunded'], true)) {
            return;
        }
        $refunded = (int) DB::table('refunds')->where('order_id', $orderId)->where('status', 'succeeded')->sum('amount');
        if ($refunded < 1) {
            return;
        }
        $financial = $refunded >= $this->int($payment, 'amount') ? 'refunded' : 'partially_refunded';
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
