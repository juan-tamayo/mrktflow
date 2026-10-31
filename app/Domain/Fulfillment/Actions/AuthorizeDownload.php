<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\CheckoutOrders\Actions\RecordOrderEvent;
use App\Domain\Fulfillment\Data\DownloadPrincipal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Private streaming download grants (FUL-05).
 *
 * Issuing a grant stores only a SHA-256 digest of a 256-bit start token
 * bound to one entitlement, its order and the issuing principal, valid for
 * five minutes; issuing alone spends nothing. The token is consumed only
 * by the explicit start step, which rechecks the principal, the current
 * entitlement/version/order eligibility and the per-entitlement allowance
 * under the entitlement row lock — so concurrent starts for the last
 * allowance create exactly one transfer — and creates a transfer session
 * bound to the principal and the pinned asset version for one hour. The
 * owner-only status read recovers a consumed grant's transfer after a lost
 * start response; a replayed token can neither create another transfer nor
 * spend again. Streams recheck everything on every request and hold a
 * single-stream lease. Revocation blocks later requests; bytes already
 * transferred are never claimed recallable.
 */
final class AuthorizeDownload
{
    private const TOKEN_TTL_SECONDS = 300;

    private const TRANSFER_TTL_SECONDS = 3600;

    private const LEASE_TTL_SECONDS = 120;

    /**
     * @return array{grant_id: string, token: string, expires_at: CarbonImmutable}
     */
    public function issue(DownloadPrincipal $principal, string $entitlementId): array
    {
        abort_unless(Str::isUuid($entitlementId) && $entitlementId === strtolower($entitlementId), 404);
        $context = $this->context($entitlementId);
        abort_unless($context !== null && $this->principalOwns($principal, $context), 404);
        abort_unless($this->eligible($context), 403);

        $id = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        $expiresAt = CarbonImmutable::now()->addSeconds(self::TOKEN_TTL_SECONDS);
        DB::table('download_grants')->insert([
            'id' => $id,
            'entitlement_id' => $entitlementId,
            'order_id' => $this->string($context, 'order_id'),
            'customer_id' => $principal->customerId,
            'token_digest' => hash('sha256', $token),
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['grant_id' => $id, 'token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * @return array{grant_id: string, transfer_id: string, expires_at: CarbonImmutable}
     */
    public function start(DownloadPrincipal $principal, string $grantId, #[\SensitiveParameter] string $token): array
    {
        abort_unless(Str::isUuid($grantId) && $grantId === strtolower($grantId)
            && preg_match('/^[a-f0-9]{64}$/D', $token) === 1, 404);
        $digest = hash('sha256', $token);

        return DB::transaction(function () use ($principal, $grantId, $digest): array {
            $peek = DB::table('download_grants')->where('id', $grantId)->first();
            abort_unless($peek !== null, 404);
            // The entitlement row lock is the allowance serialization
            // point: every start for this entitlement queues behind it.
            $entitlement = DB::table('entitlements')->where('id', $this->string($peek, 'entitlement_id'))
                ->lockForUpdate()->first();
            $grant = DB::table('download_grants')->where('id', $grantId)->lockForUpdate()->first();
            abort_unless($entitlement !== null && $grant !== null, 404);
            abort_unless($this->grantMatchesPrincipal($principal, $grant), 404);
            $storedDigest = $this->nullableString($grant, 'token_digest');
            abort_unless($storedDigest !== null && hash_equals($storedDigest, $digest), 404);
            abort_unless($grant->consumed_at === null && $grant->revoked_at === null
                && CarbonImmutable::parse($grant->expires_at)->isFuture(), 404);
            $context = $this->context($this->string($grant, 'entitlement_id'), $entitlement);
            abort_unless($context !== null && $this->principalOwns($principal, $context), 404);
            abort_unless($this->eligible($context), 403);
            $used = $this->int($entitlement, 'downloads_used');
            abort_unless($used < $this->int($entitlement, 'download_limit'), 403);

            $transferId = (string) Str::uuid();
            $expiresAt = CarbonImmutable::now()->addSeconds(self::TRANSFER_TTL_SECONDS);
            DB::table('download_transfers')->insert([
                'id' => $transferId,
                'grant_id' => $grantId,
                'entitlement_id' => $this->string($grant, 'entitlement_id'),
                'order_id' => $this->string($grant, 'order_id'),
                'customer_id' => $principal->customerId,
                'digital_asset_version_id' => $this->string($entitlement, 'digital_asset_version_id'),
                'session_binding' => hash('sha256', $principal->sessionId),
                'status' => 'active',
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('download_grants')->where('id', $grantId)->update([
                'consumed_at' => now(), 'token_digest' => null, 'transfer_id' => $transferId, 'updated_at' => now(),
            ]);
            DB::table('entitlements')->where('id', $entitlement->id)->update([
                'downloads_used' => $used + 1, 'updated_at' => now(),
            ]);
            app(RecordOrderEvent::class)->record($this->string($grant, 'order_id'), 'download_started', [
                'grant_id' => $grantId,
                'transfer_id' => $transferId,
                'entitlement_id' => $this->string($grant, 'entitlement_id'),
            ], $grantId);

            return ['grant_id' => $grantId, 'transfer_id' => $transferId, 'expires_at' => $expiresAt];
        });
    }

    /**
     * Owner-only grant status: the recovery path for a lost start response.
     *
     * @return array<string, mixed>
     */
    public function status(DownloadPrincipal $principal, string $grantId): array
    {
        abort_unless(Str::isUuid($grantId) && $grantId === strtolower($grantId), 404);
        $grant = DB::table('download_grants')->where('id', $grantId)->first();
        abort_unless($grant !== null && $this->grantMatchesPrincipal($principal, $grant), 404);
        $context = $this->context($this->string($grant, 'entitlement_id'));
        abort_unless($context !== null, 404);

        $state = 'issued';
        if ($grant->revoked_at !== null) {
            $state = 'revoked';
        } elseif ($grant->consumed_at !== null) {
            $state = 'consumed';
        } elseif (! CarbonImmutable::parse($grant->expires_at)->isFuture()) {
            $state = 'expired';
        }
        $transfer = null;
        $transferId = $this->nullableString($grant, 'transfer_id');
        if ($transferId !== null) {
            $row = DB::table('download_transfers')->where('id', $transferId)->first();
            if ($row !== null) {
                $transferState = 'active';
                if ($this->string($row, 'status') === 'revoked') {
                    $transferState = 'revoked';
                } elseif ($this->string($row, 'status') === 'expired'
                    || ! CarbonImmutable::parse($row->expires_at)->isFuture()) {
                    $transferState = 'expired';
                }
                $transfer = [
                    'id' => $transferId,
                    'status' => $this->string($row, 'status'),
                    'state' => $transferState,
                    'expires_at' => CarbonImmutable::parse($row->expires_at)->toIso8601String(),
                ];
            }
        }
        $used = $this->int($context, 'downloads_used');
        $limit = $this->int($context, 'download_limit');

        return [
            'grant_id' => $grantId,
            'state' => $state,
            'grant_expires_at' => CarbonImmutable::parse($grant->expires_at)->toIso8601String(),
            'order_id' => $this->string($context, 'order_id'),
            'order_number' => $this->string($context, 'order_number'),
            'label' => $this->string($context, 'label'),
            'title' => $this->string($context, 'title'),
            'sku' => $this->string($context, 'sku'),
            'version' => $this->int($context, 'version'),
            'downloads_used' => $used,
            'download_limit' => $limit,
            'downloads_remaining' => max(0, $limit - $used),
            'transfer' => $transfer,
        ];
    }

    /**
     * Authorizes one new stream (full or Range) on a live transfer and
     * claims its single-stream lease for this session.
     *
     * @return array{transfer_id: string, media_key: string, content_type: string, bytes: int, filename: string}
     */
    public function beginStream(DownloadPrincipal $principal, string $transferId): array
    {
        abort_unless(Str::isUuid($transferId) && $transferId === strtolower($transferId), 404);

        return DB::transaction(function () use ($principal, $transferId): array {
            $transfer = DB::table('download_transfers')->where('id', $transferId)->lockForUpdate()->first();
            abort_unless($transfer !== null, 404);
            abort_unless($this->transferMatchesPrincipal($principal, $transfer), 404);
            $context = $this->context($this->string($transfer, 'entitlement_id'));
            abort_unless($context !== null && $this->principalOwns($principal, $context), 404);
            abort_unless($this->string($transfer, 'status') !== 'revoked', 403);
            abort_if($this->string($transfer, 'status') === 'expired'
                || ! CarbonImmutable::parse($transfer->expires_at)->isFuture(), 410);
            abort_unless($this->eligible($context), 403);
            $leaseOwner = hash('sha256', $principal->sessionId);
            $currentOwner = $this->nullableString($transfer, 'stream_lease_owner');
            $leaseLive = $currentOwner !== null && $transfer->stream_lease_expires_at !== null
                && CarbonImmutable::parse($transfer->stream_lease_expires_at)->isFuture();
            abort_if($leaseLive && ! hash_equals((string) $currentOwner, $leaseOwner), 409);
            DB::table('download_transfers')->where('id', $transferId)->update([
                'stream_lease_owner' => $leaseOwner,
                'stream_lease_expires_at' => CarbonImmutable::now()->addSeconds(self::LEASE_TTL_SECONDS),
                'updated_at' => now(),
            ]);

            return [
                'transfer_id' => $transferId,
                'media_key' => $this->string($context, 'media_key'),
                'content_type' => $this->string($context, 'content_type'),
                'bytes' => $this->int($context, 'version_bytes'),
                'filename' => $this->string($context, 'original_name'),
            ];
        });
    }

    public function endStream(string $transferId, string $sessionId): void
    {
        DB::table('download_transfers')->where('id', $transferId)
            ->where('stream_lease_owner', hash('sha256', $sessionId))
            ->update(['stream_lease_owner' => null, 'stream_lease_expires_at' => null, 'updated_at' => now()]);
    }

    /**
     * The entitlement, its order, its pinned version and the version's
     * media asset, or null when any link is missing.
     */
    private function context(string $entitlementId, ?object $lockedEntitlement = null): ?object
    {
        $row = DB::table('entitlements')
            ->join('orders', 'orders.id', '=', 'entitlements.order_id')
            ->join('digital_asset_versions', 'digital_asset_versions.id', '=', 'entitlements.digital_asset_version_id')
            ->join('digital_assets', 'digital_assets.id', '=', 'digital_asset_versions.digital_asset_id')
            ->join('media_assets', 'media_assets.id', '=', 'digital_asset_versions.media_asset_id')
            ->join('order_lines', 'order_lines.id', '=', 'entitlements.order_line_id')
            ->where('entitlements.id', $entitlementId)
            ->first([
                'entitlements.id as entitlement_id',
                'entitlements.customer_id as entitlement_customer_id',
                'entitlements.status as entitlement_status',
                'entitlements.downloads_used',
                'entitlements.download_limit',
                'orders.id as order_id',
                'orders.number as order_number',
                'orders.customer_id as order_customer_id',
                'orders.download_status',
                'digital_asset_versions.status as version_status',
                'digital_asset_versions.version',
                'digital_asset_versions.bytes as version_bytes',
                'digital_asset_versions.content_type',
                'digital_assets.label',
                'order_lines.title',
                'order_lines.sku',
                'media_assets.original_key as media_key',
                'media_assets.original_name',
            ]);
        if ($row === null) {
            return null;
        }
        if ($lockedEntitlement !== null) {
            // The locked row is authoritative for the counters and status.
            $row->entitlement_status = $this->string($lockedEntitlement, 'status');
            $row->downloads_used = $this->int($lockedEntitlement, 'downloads_used');
            $row->download_limit = $this->int($lockedEntitlement, 'download_limit');
        }

        return $row;
    }

    private function principalOwns(DownloadPrincipal $principal, object $context): bool
    {
        $entitlementCustomer = $this->nullableString($context, 'entitlement_customer_id');
        $orderCustomer = $this->nullableString($context, 'order_customer_id');
        if ($principal->isAccount()) {
            return $entitlementCustomer === $principal->customerId && $orderCustomer === $principal->customerId;
        }

        return $entitlementCustomer === null && $orderCustomer === null
            && $this->string($context, 'order_id') === $principal->orderId;
    }

    private function grantMatchesPrincipal(DownloadPrincipal $principal, object $grant): bool
    {
        if ($principal->isAccount()) {
            return $this->nullableString($grant, 'customer_id') === $principal->customerId;
        }

        return $this->nullableString($grant, 'customer_id') === null
            && $this->string($grant, 'order_id') === $principal->orderId;
    }

    private function transferMatchesPrincipal(DownloadPrincipal $principal, object $transfer): bool
    {
        if ($principal->isAccount()) {
            return $this->nullableString($transfer, 'customer_id') === $principal->customerId;
        }

        return $this->nullableString($transfer, 'customer_id') === null
            && $this->string($transfer, 'order_id') === $principal->orderId;
    }

    private function eligible(object $context): bool
    {
        return $this->string($context, 'entitlement_status') === 'active'
            && $this->string($context, 'version_status') === 'ready'
            && $this->string($context, 'download_status') === 'entitled';
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableString(object $row, string $key): ?string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
