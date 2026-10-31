<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\CheckoutOrders\Actions\RecordOrderEvent;
use App\Domain\Fulfillment\Exceptions\FulfillmentIdempotencyConflict;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deliberately restores a buyer's download allowance (FUL-06).
 *
 * Revocation never returns spent allowance (T052), so a reset is a
 * separate reasoned staff act: it zeroes `downloads_used` and may
 * replace `download_limit` inside the same 1..1000 bounds the schema
 * enforces. A reset that would change nothing is rejected rather than
 * recorded as a success nobody can distinguish from a real one.
 */
final class ResetDownloadLimit extends BaseEntitlementAction
{
    public const OPERATION = 'entitlement-limit-reset';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function reset(StaffAccount $actor, string $orderId, string $entitlementId, array $input, string $idempotencyKey): array
    {
        $this->authorize($actor);
        $this->assertId($orderId);
        $this->assertId($entitlementId);
        $this->assertKey($idempotencyKey);

        return DB::transaction(function () use ($actor, $orderId, $entitlementId, $input, $idempotencyKey): array {
            $this->lockOrder($orderId);
            $entitlement = $this->lockEntitlement($orderId, $entitlementId);
            $reason = $this->reason($input['reason'] ?? null);
            $limit = $this->limit($input['download_limit'] ?? null);
            $hash = hash('sha256', json_encode([
                'order_id' => $orderId,
                'entitlement_id' => $entitlementId,
                'download_limit' => $limit,
                'reason' => $reason,
            ], JSON_THROW_ON_ERROR));
            $claim = $this->idempotency($actor, self::OPERATION, $idempotencyKey, $hash);
            if ($claim['replay']) {
                if ($claim['resource_id'] === null) {
                    throw new FulfillmentIdempotencyConflict;
                }

                return $this->view($claim['resource_id']);
            }
            $this->assertActionable($entitlement);
            $previousLimit = $this->int($entitlement, 'download_limit');
            $previousUsed = $this->int($entitlement, 'downloads_used');
            $newLimit = $limit ?? $previousLimit;
            if ($previousUsed === 0 && $newLimit === $previousLimit) {
                throw ValidationException::withMessages(['downloads' => 'This entitlement already has its full allowance.']);
            }
            DB::table('entitlements')->where('id', $entitlementId)->update([
                'download_limit' => $newLimit,
                'downloads_used' => 0,
                'updated_at' => now(),
            ]);
            app(RecordOrderEvent::class)->record($orderId, 'entitlement_limit_reset', [
                'entitlement_id' => $entitlementId,
                'previous_limit' => $previousLimit,
                'download_limit' => $newLimit,
                'previous_used' => $previousUsed,
                'reason' => $reason,
            ], $claim['id']);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:entitlement-limit-reset:'.$claim['id'], [
                'action' => 'fulfillment.entitlement_limit_reset',
                'subject_type' => 'order',
                'subject_id' => $orderId,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['version' => 1, 'entitlement_id' => $entitlementId],
            ]);
            $this->finishClaim($claim['id'], $entitlementId);

            return $this->view($entitlementId);
        });
    }

    private function limit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < 1 || $value > 1000) {
            throw ValidationException::withMessages(['download_limit' => 'The download limit must be between 1 and 1000.']);
        }

        return $value;
    }
}
