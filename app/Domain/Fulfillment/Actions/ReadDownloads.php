<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\Identity\Models\CustomerAccount;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The download area read model (FUL-04). Only `active` entitlements whose
 * pinned asset version is `ready` expose a file; suspended, revoked and
 * expired entitlements expose nothing. The exposed fields are the line
 * title/SKU and the asset label/version/size — storage keys, checksums and
 * media paths never leave the catalog tables.
 */
final class ReadDownloads
{
    /**
     * @return array{orders: list<array<string, mixed>>, claimable: int}
     */
    public function forCustomer(CustomerAccount $actor): array
    {
        $orders = [];
        foreach (DB::table('orders')->where('customer_id', $actor->id)->orderByDesc('created_at')->orderByDesc('id')->get() as $order) {
            $downloads = $this->downloadsForOrder($this->string($order, 'id'));
            if ($downloads === []) {
                continue;
            }
            $orders[] = [
                'id' => $this->string($order, 'id'),
                'number' => $this->string($order, 'number'),
                'downloads' => $downloads,
            ];
        }

        return ['orders' => $orders, 'claimable' => $this->claimableCount($actor)];
    }

    /**
     * @return array{id: string, number: string, downloads: list<array<string, mixed>>}
     */
    public function forOrder(string $orderId): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first();
        if ($order === null) {
            throw new NotFoundHttpException;
        }

        return [
            'id' => $orderId,
            'number' => $this->string($order, 'number'),
            'downloads' => $this->downloadsForOrder($orderId),
        ];
    }

    /**
     * Guest orders this account could claim: visible only to a verified
     * account, so an unverified registration learns nothing.
     */
    public function claimableCount(CustomerAccount $actor): int
    {
        if ($actor->email_verified_at === null || ! $actor->is_active) {
            return 0;
        }

        return DB::table('orders')->whereNull('customer_id')
            ->where('email', $actor->email)->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function downloadsForOrder(string $orderId): array
    {
        $downloads = [];
        $rows = DB::table('entitlements')
            ->join('order_lines', 'order_lines.id', '=', 'entitlements.order_line_id')
            ->join('digital_asset_versions', 'digital_asset_versions.id', '=', 'entitlements.digital_asset_version_id')
            ->join('digital_assets', 'digital_assets.id', '=', 'digital_asset_versions.digital_asset_id')
            ->where('entitlements.order_id', $orderId)
            ->where('entitlements.status', 'active')
            ->where('digital_asset_versions.status', 'ready')
            ->orderBy('order_lines.sku')->orderBy('order_lines.id')
            ->get([
                'entitlements.id as entitlement_id',
                'entitlements.downloads_used',
                'entitlements.download_limit',
                'order_lines.title',
                'order_lines.sku',
                'digital_assets.label',
                'digital_asset_versions.version',
                'digital_asset_versions.bytes',
                'digital_asset_versions.content_type',
            ]);
        foreach ($rows as $row) {
            $used = $this->int($row, 'downloads_used');
            $limit = $this->int($row, 'download_limit');
            $downloads[] = [
                'entitlement_id' => $this->string($row, 'entitlement_id'),
                'title' => $this->string($row, 'title'),
                'sku' => $this->string($row, 'sku'),
                'label' => $this->string($row, 'label'),
                'version' => $this->int($row, 'version'),
                'bytes' => $this->int($row, 'bytes'),
                'content_type' => $this->string($row, 'content_type'),
                'downloads_used' => $used,
                'download_limit' => $limit,
                'downloads_remaining' => max(0, $limit - $used),
            ];
        }

        return $downloads;
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
