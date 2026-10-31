<?php

declare(strict_types=1);

namespace App\Domain\ShoppingTools\Actions;

use App\Domain\ShoppingTools\Exceptions\WishlistItemNotFound;
use App\Domain\ShoppingTools\Support\WishlistOwner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Save and remove operations for one owner's list (SHOP-01).
 *
 * Saving is a set operation, not a quantity: saving the same variant
 * twice keeps one row and refreshes the saved timestamp, which is the
 * intent the login merge later compares. Only a listed variant — a
 * published product's listed variant — can be saved; draft, archived
 * and unknown variants all fail with the same generic message so the
 * storefront cannot be used to probe unpublished catalog content.
 * Saving never creates a delivery contact, a purpose grant or a watch
 * subscription: a save is not an opt-in (SHOP-02).
 */
final class SaveWishlistItem
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: ?string, items: list<array<string, mixed>>}
     */
    public function save(WishlistOwner $owner, array $input): array
    {
        $variantId = $this->listedVariantId($input);

        return DB::transaction(function () use ($owner, $variantId): array {
            $wishlistId = $this->lockOrCreate($owner);
            $existing = DB::table('wishlist_items')
                ->where('wishlist_id', $wishlistId)
                ->where('variant_id', $variantId)
                ->lockForUpdate()
                ->first();
            $now = now();
            if ($existing === null) {
                DB::table('wishlist_items')->insert([
                    'id' => (string) Str::uuid(),
                    'wishlist_id' => $wishlistId,
                    'variant_id' => $variantId,
                    'saved_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('wishlist_items')->where('id', $existing->id)->update([
                    'saved_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return app(ReadWishlist::class)->hydrate($wishlistId);
        });
    }

    /**
     * @return array{id: ?string, items: list<array<string, mixed>>}
     */
    public function remove(WishlistOwner $owner, string $itemId): array
    {
        return DB::transaction(function () use ($owner, $itemId): array {
            $wishlist = $this->lockOwned($owner);
            if ($wishlist === null) {
                throw new WishlistItemNotFound;
            }
            $existing = DB::table('wishlist_items')
                ->where('wishlist_id', $wishlist['id'])
                ->where('id', $itemId)
                ->lockForUpdate()
                ->first();
            if ($existing === null) {
                throw new WishlistItemNotFound;
            }
            DB::table('wishlist_items')->where('id', $existing->id)->delete();

            return app(ReadWishlist::class)->hydrate($wishlist['id']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function listedVariantId(array $input): string
    {
        $variantId = $input['variant_id'] ?? null;
        if (is_string($variantId) && $variantId !== '') {
            $listed = DB::table('product_variants')
                ->join('products', 'product_variants.product_id', '=', 'products.id')
                ->where('product_variants.id', $variantId)
                ->where('products.status', 'published')
                ->where('product_variants.available', true)
                ->exists();
            if (! $listed) {
                throw ValidationException::withMessages([
                    'variant_id' => 'This item is no longer available.',
                ]);
            }

            return $variantId;
        }
        $productId = $input['product_id'] ?? null;
        if (is_string($productId) && $productId !== '') {
            $variant = DB::table('product_variants')
                ->join('products', 'product_variants.product_id', '=', 'products.id')
                ->where('product_variants.product_id', $productId)
                ->where('products.status', 'published')
                ->where('product_variants.available', true)
                ->orderByDesc('product_variants.is_default')
                ->orderBy('product_variants.created_at')
                ->orderBy('product_variants.id')
                ->select('product_variants.id')
                ->first();
            if ($variant === null) {
                throw ValidationException::withMessages([
                    'product_id' => 'This item is no longer available.',
                ]);
            }

            return (string) $variant->id;
        }

        throw ValidationException::withMessages([
            'variant_id' => 'Choose an item to save.',
        ]);
    }

    private function lockOrCreate(WishlistOwner $owner): string
    {
        $locked = $this->lockOwned($owner);
        if ($locked !== null) {
            return $locked['id'];
        }
        $id = (string) Str::uuid();
        $now = now();
        try {
            DB::table('wishlists')->insert([
                'id' => $id,
                'customer_id' => $owner->customerId,
                'guest_token_hash' => $owner->guestTokenHash,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            $locked = $this->lockOwned($owner);
            if ($locked === null) {
                throw new RuntimeException('Wishlist owner could not be locked.');
            }

            return $locked['id'];
        }

        return $id;
    }

    /**
     * @return array{id: string}|null
     */
    private function lockOwned(WishlistOwner $owner): ?array
    {
        $query = DB::table('wishlists')->lockForUpdate();
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
}
