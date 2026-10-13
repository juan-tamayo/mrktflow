<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\Catalog\Exceptions\CatalogProductNotFound;
use App\Domain\Catalog\Exceptions\CatalogProductUnpublished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReadCatalog implements CatalogReader
{
    public function publicProduct(string $idOrSlug): array
    {
        $row = $this->load($idOrSlug);
        if ($row === null) {
            throw new CatalogProductNotFound;
        }
        if ($row['status'] !== 'published') {
            throw new CatalogProductUnpublished;
        }

        return $this->snapshot($row, false);
    }

    public function staffProduct(string $idOrSlug): array
    {
        $row = $this->load($idOrSlug);
        if ($row === null) {
            throw new CatalogProductNotFound;
        }

        $product = $this->snapshot($row, true);
        $product['version'] = (int) $row['version'];
        $product['image']['assetId'] = is_string($row['media_asset_id'] ?? null) ? $row['media_asset_id'] : '';

        return $product;
    }

    public function publishedCount(): int
    {
        return (int) DB::table('products')->where('status', 'published')->count();
    }

    /** @return array<string, mixed>|null */
    private function load(string $idOrSlug): ?array
    {
        $query = DB::table('products')
            ->join('product_variants', function ($join): void {
                $join->on('products.id', '=', 'product_variants.product_id')->where('product_variants.is_default', '=', true);
            })
            ->join('product_media', function ($join): void {
                $join->on('products.id', '=', 'product_media.product_id')->where('product_media.sort_order', '=', 0);
            })
            ->join('media_assets', 'product_media.media_asset_id', '=', 'media_assets.id')
            ->select([
                'products.id', 'products.slug', 'products.title', 'products.description', 'products.type',
                'products.status', 'products.version', 'product_variants.id as variant_id', 'product_variants.sku',
                'product_variants.price_amount', 'product_variants.currency', 'product_variants.exponent',
                'product_media.alt', 'product_media.media_asset_id', 'media_assets.public_key', 'media_assets.kind',
                'media_assets.status as media_status',
            ]);
        $record = Str::isUuid($idOrSlug)
            ? $query->where('products.id', $idOrSlug)->first()
            : $query->where('products.slug', $idOrSlug)->first();

        return is_object($record) ? get_object_vars($record) : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, slug: string, title: string, description: string, type: string, status: string, version?: int, image: array{url: string, alt: string, assetId?: string}, variant: array{id: string, sku: string, isDefault: bool, money: array{amount: int, currency: string, exponent: int}}}
     */
    private function snapshot(array $row, bool $staff): array
    {
        $publicKey = is_string($row['public_key'] ?? null) ? $row['public_key'] : '';
        $id = is_string($row['id'] ?? null) ? $row['id'] : '';
        $slug = is_string($row['slug'] ?? null) ? $row['slug'] : '';
        $title = is_string($row['title'] ?? null) ? $row['title'] : '';
        $description = is_string($row['description'] ?? null) ? $row['description'] : '';
        $type = is_string($row['type'] ?? null) ? $row['type'] : '';
        $status = is_string($row['status'] ?? null) ? $row['status'] : '';
        $alt = is_string($row['alt'] ?? null) ? $row['alt'] : '';
        $variantId = is_string($row['variant_id'] ?? null) ? $row['variant_id'] : '';
        $sku = is_string($row['sku'] ?? null) ? $row['sku'] : '';
        $currency = is_string($row['currency'] ?? null) ? $row['currency'] : '';
        $kind = is_string($row['kind'] ?? null) ? $row['kind'] : '';
        $mediaStatus = is_string($row['media_status'] ?? null) ? $row['media_status'] : '';
        if ($kind !== 'public_image' || $mediaStatus !== 'ready' || $id === '' || $variantId === ''
            || ! preg_match('#^media/[0-9a-f-]{36}/[a-f0-9]{64}\.png$#D', $publicKey)) {
            throw $staff ? new CatalogProductNotFound : new CatalogProductUnpublished;
        }
        $product = [
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'description' => $description,
            'type' => $type,
            'status' => $status,
            'image' => ['url' => '/storage/'.$publicKey, 'alt' => $alt],
            'variant' => [
                'id' => $variantId,
                'sku' => $sku,
                'isDefault' => true,
                'money' => [
                    'amount' => (int) $row['price_amount'],
                    'currency' => $currency,
                    'exponent' => (int) $row['exponent'],
                ],
            ],
        ];
        return $product;
    }
}
