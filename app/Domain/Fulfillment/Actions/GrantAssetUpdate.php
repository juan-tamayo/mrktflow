<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\CheckoutOrders\Actions\RecordOrderEvent;
use App\Domain\Fulfillment\Exceptions\FulfillmentIdempotencyConflict;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Grants one buyer a newer version of a purchased file (FUL-06).
 *
 * Entitlements are pinned to the version frozen on the order line at
 * purchase (T049), so an update is a deliberate, reasoned staff act:
 * only this entitlement's pin moves, to a strictly newer `ready`
 * version of the same digital asset. The order line's frozen pin, the
 * version history and every other buyer's entitlement stay untouched —
 * a buyer who was not granted the update keeps downloading the version
 * they purchased, and transfers already started keep the version they
 * were bound to (T051 reads the entitlement's current pin only when a
 * new transfer starts).
 */
final class GrantAssetUpdate extends BaseEntitlementAction
{
    public const OPERATION = 'entitlement-update';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function grant(StaffAccount $actor, string $orderId, string $entitlementId, array $input, string $idempotencyKey): array
    {
        $this->authorize($actor);
        $this->assertId($orderId);
        $this->assertId($entitlementId);
        $this->assertKey($idempotencyKey);

        return DB::transaction(function () use ($actor, $orderId, $entitlementId, $input, $idempotencyKey): array {
            $this->lockOrder($orderId);
            $entitlement = $this->lockEntitlement($orderId, $entitlementId);
            $reason = $this->reason($input['reason'] ?? null);
            $versionId = $input['version_id'] ?? null;
            if (! is_string($versionId) || ! Str::isUuid($versionId) || $versionId !== strtolower($versionId)) {
                throw ValidationException::withMessages(['version_id' => 'Choose the asset version to grant.']);
            }
            $hash = hash('sha256', json_encode([
                'order_id' => $orderId,
                'entitlement_id' => $entitlementId,
                'version_id' => $versionId,
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
            $current = DB::table('digital_asset_versions')
                ->where('id', $this->string($entitlement, 'digital_asset_version_id'))->lockForUpdate()->first();
            if ($current === null) {
                throw new NotFoundHttpException;
            }
            $target = DB::table('digital_asset_versions')->where('id', $versionId)->lockForUpdate()->first();
            if ($target === null) {
                throw ValidationException::withMessages(['version_id' => 'Choose a digital file version that exists.']);
            }
            if ($this->string($target, 'digital_asset_id') !== $this->string($current, 'digital_asset_id')) {
                throw ValidationException::withMessages(['version_id' => 'The granted version must belong to the purchased file.']);
            }
            if ($this->string($target, 'status') !== 'ready') {
                throw ValidationException::withMessages(['version_id' => 'Only a ready version can be granted.']);
            }
            if ($this->int($target, 'version') <= $this->int($current, 'version')) {
                throw ValidationException::withMessages(['version_id' => 'The granted version must be newer than the version the buyer already has.']);
            }
            DB::table('entitlements')->where('id', $entitlementId)->update([
                'digital_asset_version_id' => $versionId,
                'updated_at' => now(),
            ]);
            app(RecordOrderEvent::class)->record($orderId, 'entitlement_update_granted', [
                'entitlement_id' => $entitlementId,
                'from_version_id' => $this->string($current, 'id'),
                'to_version_id' => $versionId,
                'from_version' => $this->int($current, 'version'),
                'to_version' => $this->int($target, 'version'),
                'reason' => $reason,
            ], $claim['id']);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:entitlement-update:'.$claim['id'], [
                'action' => 'fulfillment.entitlement_update_granted',
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
}
