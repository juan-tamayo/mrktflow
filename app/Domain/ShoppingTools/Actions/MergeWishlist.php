<?php

declare(strict_types=1);

namespace App\Domain\ShoppingTools\Actions;

use App\Domain\ShoppingTools\Support\WishlistOwner;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Login union for saved products (SHOP-01).
 *
 * The guest list folds into the account list keyed by variant: a
 * variant saved on both sides collapses to the account row, keeping
 * the newest saved timestamp as the surviving intent; guest-only
 * variants move across. The guest list is consumed either way, so a
 * later sign-in on the same browser — by anyone — starts from an
 * empty guest scope and can never inherit a previous shopper's saves.
 * Repeating the merge (every later login) is a no-op.
 */
final class MergeWishlist
{
    /**
     * @return array{id: ?string, items: list<array<string, mixed>>}
     */
    public function merge(WishlistOwner $guest, WishlistOwner $customer): array
    {
        if ($guest->customerId !== null || $guest->guestTokenHash === null
            || $customer->customerId === null || $customer->guestTokenHash !== null) {
            throw new InvalidArgumentException('Wishlist merge requires a guest list and a customer list.');
        }

        return DB::transaction(function () use ($guest, $customer): array {
            $rows = DB::table('wishlists')
                ->where(function (Builder $query) use ($guest, $customer): void {
                    $query->where('guest_token_hash', $guest->guestTokenHash)
                        ->orWhere('customer_id', $customer->customerId);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $guestList = null;
            $customerList = null;
            foreach ($rows as $row) {
                if (($row->guest_token_hash ?? null) === $guest->guestTokenHash) {
                    $guestList = $row;
                }
                if (($row->customer_id ?? null) === $customer->customerId) {
                    $customerList = $row;
                }
            }
            if ($guestList === null) {
                return app(ReadWishlist::class)->snapshot($customer);
            }
            $guestItems = DB::table('wishlist_items')
                ->where('wishlist_id', $guestList->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($guestItems->isEmpty()) {
                DB::table('wishlists')->where('id', $guestList->id)->delete();

                return app(ReadWishlist::class)->snapshot($customer);
            }
            if ($customerList === null) {
                DB::table('wishlists')->where('id', $guestList->id)->update([
                    'customer_id' => $customer->customerId,
                    'guest_token_hash' => null,
                    'updated_at' => now(),
                ]);

                return app(ReadWishlist::class)->hydrate((string) $guestList->id);
            }

            $accountItems = DB::table('wishlist_items')
                ->where('wishlist_id', $customerList->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $accountByVariant = [];
            foreach ($accountItems as $item) {
                $accountByVariant[(string) $item->variant_id] = $item;
            }
            $now = now();
            foreach ($guestItems as $item) {
                $variantId = (string) $item->variant_id;
                $existing = $accountByVariant[$variantId] ?? null;
                if ($existing !== null) {
                    if ((string) $item->saved_at > (string) $existing->saved_at) {
                        DB::table('wishlist_items')->where('id', $existing->id)->update([
                            'saved_at' => $item->saved_at,
                            'updated_at' => $now,
                        ]);
                    }
                    DB::table('wishlist_items')->where('id', $item->id)->delete();
                } else {
                    DB::table('wishlist_items')->where('id', $item->id)->update([
                        'wishlist_id' => $customerList->id,
                        'updated_at' => $now,
                    ]);
                    $accountByVariant[$variantId] = $item;
                }
            }
            DB::table('wishlists')->where('id', $guestList->id)->delete();

            return app(ReadWishlist::class)->hydrate((string) $customerList->id);
        });
    }
}
