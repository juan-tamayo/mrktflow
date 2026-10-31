<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\CheckoutOrders\Actions\RecordOrderEvent;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Keeps already-issued entitlements in step with the authoritative money
 * facts (FUL-05). The money paths invoke this sync inside the same
 * transaction that records the refund, cancellation or dispute fact, so
 * access changes atomically with the fact that causes it — there is no
 * parallel flag to forget.
 *
 * Every entitlement is recomputed from the current order, line and
 * dispute rows under row locks: a cancelled order, a lost dispute, a
 * whole-order refund, a revoked download projection or the entitlement's
 * own fully refunded line revokes; an open dispute suspends; anything
 * else is eligible. Revoked and expired rows are terminal here and never
 * move, a suspended row returns to active only when the recomputation
 * says eligible again, and a sync that changes nothing records nothing —
 * so replays and redeliveries of the same money fact are silent no-ops.
 *
 * Revocation also revokes the entitlement's unconsumed download grants
 * and its active transfer sessions, so a start or stream that rechecks
 * afterwards rejects. Suspension deliberately leaves grants and
 * transfers in place: T051 start/stream rechecks reject on entitlement
 * status while suspended, and a restore re-enables the same session
 * inside its hour. Revocation blocks later requests only; bytes already
 * transferred are never claimed recallable.
 */
final class RevokeEntitlements
{
    /**
     * @return array{revoked: list<string>, suspended: list<string>, restored: list<string>}
     */
    public function syncForOrder(string $orderId, ?string $sourceEventId = null, string $auditSource = 'sync'): array
    {
        if (! Str::isUuid($orderId) || $orderId !== strtolower($orderId)
            || ($sourceEventId !== null && (! Str::isUuid($sourceEventId) || $sourceEventId !== strtolower($sourceEventId)))) {
            throw new InvalidArgumentException('Invalid entitlement sync.');
        }

        return DB::transaction(function () use ($orderId, $sourceEventId, $auditSource): array {
            $transitions = ['revoked' => [], 'suspended' => [], 'restored' => []];
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null) {
                return $transitions;
            }
            $lines = [];
            foreach (DB::table('order_lines')->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get() as $line) {
                $lines[$this->string($line, 'id')] = $line;
            }
            $disputeOpen = DB::table('order_disputes')->where('order_id', $orderId)->where('status', 'opened')->exists();
            $disputeLost = DB::table('order_disputes')->where('order_id', $orderId)->where('status', 'lost')->exists();
            $reasons = ['revoked' => [], 'suspended' => [], 'restored' => []];
            $entitlements = DB::table('entitlements')->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get();
            foreach ($entitlements as $entitlement) {
                $current = $this->string($entitlement, 'status');
                if (in_array($current, ['revoked', 'expired'], true)) {
                    continue;
                }
                $line = $lines[$this->string($entitlement, 'order_line_id')] ?? null;
                [$target, $reason] = $this->target($order, $line, $disputeOpen, $disputeLost);
                $id = $this->string($entitlement, 'id');
                if ($target === 'revoked') {
                    DB::table('entitlements')->where('id', $id)->update(['status' => 'revoked', 'updated_at' => now()]);
                    DB::table('download_grants')->where('entitlement_id', $id)
                        ->whereNull('consumed_at')->whereNull('revoked_at')
                        ->update(['revoked_at' => now(), 'token_digest' => null, 'updated_at' => now()]);
                    DB::table('download_transfers')->where('entitlement_id', $id)->where('status', 'active')
                        ->update([
                            'status' => 'revoked',
                            'stream_lease_owner' => null,
                            'stream_lease_expires_at' => null,
                            'updated_at' => now(),
                        ]);
                    $transitions['revoked'][] = $id;
                    $reasons['revoked'][] = $reason;
                } elseif ($target === 'suspended' && $current === 'active') {
                    DB::table('entitlements')->where('id', $id)->update(['status' => 'suspended', 'updated_at' => now()]);
                    $transitions['suspended'][] = $id;
                    $reasons['suspended'][] = $reason;
                } elseif ($target === 'active' && $current === 'suspended') {
                    DB::table('entitlements')->where('id', $id)->update(['status' => 'active', 'updated_at' => now()]);
                    $transitions['restored'][] = $id;
                    $reasons['restored'][] = 'dispute_resolved';
                }
            }
            foreach (['revoked', 'suspended', 'restored'] as $kind) {
                if ($transitions[$kind] === []) {
                    continue;
                }
                app(RecordOrderEvent::class)->record($orderId, 'entitlements_'.$kind, [
                    'entitlements' => count($transitions[$kind]),
                    'reasons' => array_values(array_unique($reasons[$kind])),
                ], $sourceEventId);
                app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-entitlements-'.$kind.':'.$orderId.':'.$auditSource, [
                    'action' => 'fulfillment.entitlements_'.$kind,
                    'subject_type' => 'order',
                    'subject_id' => $orderId,
                    'actor_type' => 'system',
                    'actor_id' => $auditSource,
                    'metadata' => ['version' => 1, 'entitlements' => count($transitions[$kind])],
                ]);
            }

            return $transitions;
        });
    }

    /**
     * The state the current authoritative facts dictate for one
     * entitlement, with revoked facts taking precedence over suspension.
     *
     * @return array{0: string, 1: string}
     */
    private function target(object $order, ?object $line, bool $disputeOpen, bool $disputeLost): array
    {
        if ($this->string($order, 'status') === 'cancelled') {
            return ['revoked', 'order_cancelled'];
        }
        if ($disputeLost) {
            return ['revoked', 'dispute_lost'];
        }
        if ($this->string($order, 'financial_status') === 'refunded') {
            return ['revoked', 'order_refunded'];
        }
        if ($this->string($order, 'download_status') === 'revoked') {
            return ['revoked', 'download_revoked'];
        }
        if ($line !== null && $this->int($line, 'quantity') > 0
            && $this->int($line, 'refunded_qty') >= $this->int($line, 'quantity')) {
            return ['revoked', 'line_refunded'];
        }
        if ($disputeOpen || $this->string($order, 'dispute_status') === 'open') {
            return ['suspended', 'dispute_opened'];
        }
        if ($this->string($order, 'download_status') === 'suspended') {
            return ['suspended', 'download_suspended'];
        }

        return ['active', 'eligible'];
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
