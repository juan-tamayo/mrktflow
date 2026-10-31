<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\Fulfillment\Exceptions\FulfillmentIdempotencyConflict;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared machinery for the deliberate staff entitlement actions
 * (FUL-06): permission, id validation, row locks, the staff
 * idempotency claim and the entitlement view both actions return.
 */
abstract class BaseEntitlementAction
{
    protected function authorize(StaffAccount $actor): void
    {
        if (! $actor->hasPermission('fulfillment.manage')) {
            throw new AuthorizationException;
        }
    }

    protected function assertKey(string $idempotencyKey): void
    {
        if (! Str::isUuid($idempotencyKey) || $idempotencyKey !== strtolower($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Send a stable download key.']);
        }
    }

    protected function assertId(string $id): void
    {
        if (! Str::isUuid($id) || $id !== strtolower($id)) {
            throw new NotFoundHttpException;
        }
    }

    protected function lockOrder(string $orderId): object
    {
        $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        if ($order === null) {
            throw new NotFoundHttpException;
        }

        return $order;
    }

    protected function lockEntitlement(string $orderId, string $entitlementId): object
    {
        $entitlement = DB::table('entitlements')->where('id', $entitlementId)->where('order_id', $orderId)->lockForUpdate()->first();
        if ($entitlement === null) {
            throw new NotFoundHttpException;
        }

        return $entitlement;
    }

    protected function reason(mixed $value): string
    {
        $reason = is_string($value) ? trim($value) : '';
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Enter a reason of at most 500 characters.']);
        }

        return $reason;
    }

    /**
     * Terminal entitlements are history: a deliberate staff action must
     * never resurrect or mutate access the money facts took away.
     */
    protected function assertActionable(object $entitlement): void
    {
        if (! in_array($this->string($entitlement, 'status'), ['active', 'suspended'], true)) {
            throw ValidationException::withMessages(['entitlement' => 'Only an active or suspended entitlement can be changed.']);
        }
    }

    /**
     * @return array{id: string, replay: bool, resource_id: ?string}
     */
    protected function idempotency(StaffAccount $actor, string $operation, string $key, string $hash): array
    {
        $scope = 'staff:'.$actor->id;
        try {
            $id = (string) Str::uuid();
            DB::table('idempotency_records')->insert([
                'id' => $id,
                'principal_scope' => $scope,
                'operation' => $operation,
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
                ->where('operation', $operation)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($existing === null) {
                throw new FulfillmentIdempotencyConflict;
            }
            if ($this->string($existing, 'payload_hash') !== $hash) {
                throw new FulfillmentIdempotencyConflict;
            }

            return [
                'id' => $this->string($existing, 'id'),
                'replay' => true,
                'resource_id' => $this->nullable($existing, 'resource_id'),
            ];
        }
    }

    protected function finishClaim(string $claimId, string $entitlementId): void
    {
        DB::table('idempotency_records')->where('id', $claimId)->update([
            'status' => 'completed',
            'resource_id' => $entitlementId,
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function view(string $entitlementId): array
    {
        $entitlement = DB::table('entitlements')->where('id', $entitlementId)->first();
        if ($entitlement === null) {
            throw new NotFoundHttpException;
        }
        $current = DB::table('digital_asset_versions')
            ->where('id', $this->string($entitlement, 'digital_asset_version_id'))->first();
        $line = DB::table('order_lines')->where('id', $this->string($entitlement, 'order_line_id'))->first();
        $purchase = $line === null ? null : DB::table('digital_asset_versions')
            ->where('id', $this->string($line, 'digital_asset_version_id'))->first();
        $limit = $this->int($entitlement, 'download_limit');
        $used = $this->int($entitlement, 'downloads_used');

        return [
            'id' => $this->string($entitlement, 'id'),
            'order_id' => $this->string($entitlement, 'order_id'),
            'order_line_id' => $this->string($entitlement, 'order_line_id'),
            'status' => $this->string($entitlement, 'status'),
            'digital_asset_version_id' => $this->string($entitlement, 'digital_asset_version_id'),
            'version' => $current === null ? 0 : $this->int($current, 'version'),
            'purchase_version_id' => $line === null ? null : $this->nullable($line, 'digital_asset_version_id'),
            'purchase_version' => $purchase === null ? 0 : $this->int($purchase, 'version'),
            'download_limit' => $limit,
            'downloads_used' => $used,
            'downloads_remaining' => max(0, $limit - $used),
        ];
    }

    protected function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    protected function nullable(object $row, string $key): ?string
    {
        $value = $this->string($row, $key);

        return $value === '' ? null : $value;
    }

    protected function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
