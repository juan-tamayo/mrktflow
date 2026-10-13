<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use Illuminate\Support\Facades\DB;

final class VariantAvailability
{
    public const IN_STOCK = 'in_stock';

    public const OUT_OF_STOCK = 'out_of_stock';

    public const UNAVAILABLE = 'unavailable';

    public static function state(bool $listed, bool $tracks, ?int $onHand, ?int $reserved, bool $digital, bool $hasReadyDigitalFile): string
    {
        if (! $listed) {
            return self::UNAVAILABLE;
        }
        if ($digital) {
            return $hasReadyDigitalFile ? self::IN_STOCK : self::UNAVAILABLE;
        }
        if ($tracks) {
            $remaining = $onHand === null ? 0 : $onHand - ($reserved ?? 0);

            return $remaining > 0 ? self::IN_STOCK : self::OUT_OF_STOCK;
        }

        return self::IN_STOCK;
    }

    public static function sellable(string $state): bool
    {
        return $state === self::IN_STOCK;
    }

    /**
     * @param  list<string>  $states
     */
    public static function productState(array $states): string
    {
        if (in_array(self::IN_STOCK, $states, true)) {
            return self::IN_STOCK;
        }
        if (in_array(self::OUT_OF_STOCK, $states, true)) {
            return self::OUT_OF_STOCK;
        }

        return self::UNAVAILABLE;
    }

    /**
     * @param  list<string>  $productIds
     * @return array<string, array{available: bool, availability: string}>
     */
    public function forProducts(array $productIds): array
    {
        $summaries = [];
        foreach ($productIds as $id) {
            $summaries[$id] = ['available' => false, 'availability' => self::UNAVAILABLE];
        }
        if ($productIds === []) {
            return $summaries;
        }
        $readyFiles = DB::table('variant_digital_assets')
            ->join('digital_asset_versions', 'variant_digital_assets.digital_asset_version_id', '=', 'digital_asset_versions.id')
            ->join('product_variants', 'variant_digital_assets.variant_id', '=', 'product_variants.id')
            ->whereIn('product_variants.product_id', $productIds)
            ->where('digital_asset_versions.status', 'ready')
            ->distinct()
            ->pluck('variant_digital_assets.variant_id')
            ->all();
        $readyLookup = [];
        foreach ($readyFiles as $variantId) {
            if (is_string($variantId)) {
                $readyLookup[$variantId] = true;
            }
        }
        $states = [];
        $rows = DB::table('product_variants')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('inventory_balances', 'product_variants.id', '=', 'inventory_balances.variant_id')
            ->whereIn('product_variants.product_id', $productIds)
            ->select([
                'product_variants.id',
                'product_variants.product_id',
                'product_variants.available',
                'product_variants.tracks_inventory',
                'products.type',
                'inventory_balances.on_hand',
                'inventory_balances.reserved',
            ])
            ->get();
        foreach ($rows as $row) {
            $productId = (string) $row->product_id;
            $variantId = (string) $row->id;
            $state = self::state(
                (bool) $row->available,
                (bool) $row->tracks_inventory,
                $row->on_hand !== null ? (int) $row->on_hand : null,
                $row->reserved !== null ? (int) $row->reserved : null,
                (string) $row->type === 'digital',
                isset($readyLookup[$variantId]),
            );
            $states[$productId][] = $state;
        }
        foreach ($states as $productId => $variantStates) {
            $availability = self::productState($variantStates);
            $summaries[$productId] = [
                'available' => self::sellable($availability),
                'availability' => $availability,
            ];
        }

        return $summaries;
    }
}
