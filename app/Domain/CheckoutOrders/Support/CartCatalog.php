<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Support;

use App\Domain\Catalog\Support\VariantAvailability;
use Illuminate\Support\Facades\DB;

final class CartCatalog
{
    /**
     * @return array{variant_id: string, product_id: string, title: string, sku: string, type: string, status: string, price_amount: int, currency: string, exponent: int, available: bool, availability: string, max_quantity: int}|null
     */
    public function variant(string $variantId): ?array
    {
        $row = DB::table('product_variants')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('inventory_balances', 'product_variants.id', '=', 'inventory_balances.variant_id')
            ->where('product_variants.id', $variantId)
            ->select([
                'product_variants.id',
                'product_variants.product_id',
                'product_variants.sku',
                'product_variants.price_amount',
                'product_variants.currency',
                'product_variants.exponent',
                'product_variants.available',
                'product_variants.tracks_inventory',
                'products.title',
                'products.type',
                'products.status',
                'inventory_balances.on_hand',
                'inventory_balances.reserved',
            ])
            ->first();
        if ($row === null) {
            return null;
        }
        $digital = (string) $row->type === 'digital';
        $ready = false;
        if ($digital) {
            $ready = DB::table('variant_digital_assets')
                ->join('digital_asset_versions', 'variant_digital_assets.digital_asset_version_id', '=', 'digital_asset_versions.id')
                ->where('variant_digital_assets.variant_id', $variantId)
                ->where('digital_asset_versions.status', 'ready')
                ->exists();
        }
        $listed = (bool) $row->available && (string) $row->status === 'published';
        $availability = VariantAvailability::state(
            $listed,
            (bool) $row->tracks_inventory,
            $row->on_hand !== null ? (int) $row->on_hand : null,
            $row->reserved !== null ? (int) $row->reserved : null,
            $digital,
            $ready,
        );
        $sellable = VariantAvailability::sellable($availability);
        $remaining = (bool) $row->tracks_inventory
            ? max(0, ($row->on_hand !== null ? (int) $row->on_hand : 0) - ($row->reserved !== null ? (int) $row->reserved : 0))
            : CartMoney::MAX_QUANTITY;
        $max = $digital ? 1 : min(CartMoney::MAX_QUANTITY, $remaining);
        if (! $sellable) {
            $max = 0;
        }

        return [
            'variant_id' => (string) $row->id,
            'product_id' => (string) $row->product_id,
            'title' => (string) $row->title,
            'sku' => (string) $row->sku,
            'type' => (string) $row->type,
            'status' => (string) $row->status,
            'price_amount' => (int) $row->price_amount,
            'currency' => (string) $row->currency,
            'exponent' => (int) $row->exponent,
            'available' => $sellable,
            'availability' => $availability,
            'max_quantity' => $max,
        ];
    }
}
