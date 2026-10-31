<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\ShoppingTools\Actions\ReadWishlist;
use App\Domain\ShoppingTools\Actions\SaveWishlistItem;
use App\Domain\ShoppingTools\Support\WishlistOwner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saved products for guests and accounts (SHOP-01, SHOP-02).
 *
 * One surface serves both owners, exactly like the cart: the owner is
 * resolved from the customer session or the anonymous session token,
 * never from posted input, so no request can read or mutate another
 * shopper's list. The page is private by construction and is never
 * cached or indexed.
 */
final class WishlistController
{
    public function show(Request $request, ReadWishlist $read): JsonResponse|View
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 503);
        $wishlist = $read->snapshot(WishlistOwner::fromRequest($request));
        if ($request->expectsJson() || $request->is('store-api/*')) {
            return response()->json($wishlist)->header('Cache-Control', 'no-store, private');
        }

        return view('default-theme::wishlist', ['wishlist' => $wishlist]);
    }

    public function store(Request $request, SaveWishlistItem $save): JsonResponse|RedirectResponse
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 503);
        try {
            $wishlist = $save->save(WishlistOwner::fromRequest($request), $request->all());
        } catch (ValidationException $exception) {
            return $this->failed($request, $exception);
        }

        return $this->saved($request, $wishlist);
    }

    public function destroy(Request $request, string $itemId, SaveWishlistItem $save): JsonResponse|RedirectResponse
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 503);
        $wishlist = $save->remove(WishlistOwner::fromRequest($request), $itemId);

        return $this->saved($request, $wishlist);
    }

    /**
     * @param  array{id: ?string, items: list<array<string, mixed>>}  $wishlist
     */
    private function saved(Request $request, array $wishlist): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('store-api/*')) {
            return response()->json($wishlist)->header('Cache-Control', 'no-store, private');
        }

        return redirect('/wishlist');
    }

    private function failed(Request $request, ValidationException $exception): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('store-api/*')) {
            $requestId = $request->attributes->get('correlation_id');

            return response()->json([
                'error' => [
                    'code' => 'VALIDATION',
                    'message' => $exception->getMessage(),
                    'fields' => (object) $exception->errors(),
                    'requestId' => is_string($requestId) ? $requestId : '',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY)->header('Cache-Control', 'no-store, private');
        }

        return redirect()->back()->withErrors($exception->errors());
    }
}
