<?php

declare(strict_types=1);

namespace App\Domain\ShoppingTools\Actions;

use App\Domain\Catalog\Support\VariantAvailability;
use App\Domain\CheckoutOrders\Support\CartMoney;
use App\Domain\ShoppingTools\Support\WishlistOwner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The saved-products read model (SHOP-02).
 *
 * Listed entries always show the catalog's current price and
 * availability — never a price frozen at save time. An entry whose
 * product has left publication (draft or archived) or whose variant is
 * no longer listed degrades to a bare placeholder: only the item id,
 * its state and the saved date survive. The draft's title, SKU, price,
 * slug and image are never exposed to the shopper who saved it, let
 * alone anyone else, while the save itself stays durable so a
 * republished product returns in full.
 */
final class ReadWishlist
{
    /**
     * @return array{id: ?string, items: list<array<string, mixed>>}
     */
    public function snapshot(WishlistOwner $owner): array
    {
        $wishlist = $this->owned($owner);
        if ($wishlist === null) {
            return ['id' => null, 'items' => []];
        }

        return $this->hydrate($wishlist['id']);
    }

    /**
     * @return array{id: string, items: list<array<string, mixed>>}
     */
    public function hydrate(string $wishlistId): array
    {
        $rows = DB::table('wishlist_items')
            ->join('product_variants', 'wishlist_items.variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('inventory_balances', 'product_variants.id', '=', 'inventory_balances.variant_id')
            ->where('wishlist_items.wishlist_id', $wishlistId)
            ->orderByDesc('wishlist_items.saved_at')
            ->orderBy('wishlist_items.id')
            ->select([
                'wishlist_items.id',
                'wishlist_items.variant_id',
                'wishlist_items.saved_at',
                'product_variants.product_id',
                'product_variants.sku',
                'product_variants.price_amount',
                'product_variants.currency',
                'product_variants.exponent',
                'product_variants.available as variant_listed',
                'product_variants.tracks_inventory',
                'products.title',
                'products.slug',
                'products.type',
                'products.status',
                'inventory_balances.on_hand',
                'inventory_balances.reserved',
            ])
            ->get();
        $readyDigital = $this->readyDigitalVariants($rows);
        $items = [];
        foreach ($rows as $row) {
            $savedAt = Carbon::parse((string) $row->saved_at)->utc()->toJSON();
            if ((string) $row->status !== 'published' || ! (bool) $row->variant_listed) {
                $items[] = [
                    'id' => (string) $row->id,
                    'state' => 'removed',
                    'saved_at' => $savedAt,
                ];

                continue;
            }
            $digital = (string) $row->type === 'digital';
            $availability = VariantAvailability::state(
                true,
                (bool) $row->tracks_inventory,
                $row->on_hand !== null ? (int) $row->on_hand : null,
                $row->reserved !== null ? (int) $row->reserved : null,
                $digital,
                isset($readyDigital[(string) $row->variant_id]),
            );
            $items[] = [
                'id' => (string) $row->id,
                'state' => 'listed',
                'variant_id' => (string) $row->variant_id,
                'product_id' => (string) $row->product_id,
                'title' => (string) $row->title,
                'sku' => (string) $row->sku,
                'type' => (string) $row->type,
                'url' => '/products/'.(string) $row->slug,
                'money' => CartMoney::format((int) $row->price_amount, (string) $row->currency, (int) $row->exponent),
                'availability' => $availability,
                'available' => VariantAvailability::sellable($availability),
                'saved_at' => $savedAt,
            ];
        }

        return ['id' => $wishlistId, 'items' => $items];
    }

    /**
     * @return array{id: string}|null
     */
    public function owned(WishlistOwner $owner): ?array
    {
        $query = DB::table('wishlists');
        if ($owner->customerId !== null) {
            $query->where('customer_id', $owner->customerId);
        } else {
            $query->where('guest_token_hash', $owner->guestTokenHash);
        }
        $row = $query->first();
        if (! is_object($row)) {
            return null;
        }
        $data = get_object_vars($row);
        $id = $data['id'] ?? null;
        if (! is_string($id)) {
            return null;
        }

        return ['id' => $id];
    }

    /**
     * @param  iterable<object>  $rows
     * @return array<string, true>
     */
    private function readyDigitalVariants(iterable $rows): array
    {
        $digitalIds = [];
        foreach ($rows as $row) {
            $data = get_object_vars($row);
            $variantId = $data['variant_id'] ?? null;
            if (($data['type'] ?? null) === 'digital' && ($data['status'] ?? null) === 'published' && is_string($variantId)) {
                $digitalIds[] = $variantId;
            }
        }
        if ($digitalIds === []) {
            return [];
        }
        $ready = DB::table('variant_digital_assets')
            ->join('digital_asset_versions', 'variant_digital_assets.digital_asset_version_id', '=', 'digital_asset_versions.id')
            ->whereIn('variant_digital_assets.variant_id', $digitalIds)
            ->where('digital_asset_versions.status', 'ready')
            ->distinct()
            ->pluck('variant_digital_assets.variant_id')
            ->all();
        $lookup = [];
        foreach ($ready as $variantId) {
            if (is_string($variantId)) {
                $lookup[$variantId] = true;
            }
        }

        return $lookup;
    }
}
