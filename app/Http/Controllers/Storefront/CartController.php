<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\CheckoutOrders\Actions\ReadCart;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\ThemesContent\Rendering\DefaultThemeRenderer;
use App\Http\Resources\CartResource;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class CartController
{
    public function show(Request $request, ReadCart $read, DefaultThemeRenderer $renderer): JsonResponse|View
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 503);
        $cart = $read->snapshot(CartOwner::fromRequest($request));
        if ($request->expectsJson() || $request->is('store-api/*')) {
            return (new CartResource($cart))->response()->header('Cache-Control', 'no-store, private');
        }

        return $renderer->cart($cart);
    }

    public function store(Request $request, UpdateCart $update): JsonResponse|RedirectResponse
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 503);
        try {
            $cart = $update->replaceLine(CartOwner::fromRequest($request), $request->all());
        } catch (ValidationException $exception) {
            return $this->failed($request, $exception);
        }

        return $this->saved($request, $cart);
    }

    public function update(Request $request, string $lineId, UpdateCart $update): JsonResponse|RedirectResponse
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 503);
        try {
            $cart = $update->changeLine(CartOwner::fromRequest($request), $lineId, $request->all());
        } catch (ValidationException $exception) {
            return $this->failed($request, $exception);
        }

        return $this->saved($request, $cart);
    }

    public function destroy(Request $request, string $lineId, UpdateCart $update): JsonResponse|RedirectResponse
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 503);
        try {
            $cart = $update->removeLine(CartOwner::fromRequest($request), $lineId, $request->all());
        } catch (ValidationException $exception) {
            return $this->failed($request, $exception);
        }

        return $this->saved($request, $cart);
    }

    /**
     * @param  array<string, mixed>  $cart
     */
    private function saved(Request $request, array $cart): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('store-api/*')) {
            return (new CartResource($cart))->response()->header('Cache-Control', 'no-store, private');
        }

        return redirect('/cart');
    }

    private function failed(Request $request, ValidationException $exception): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('store-api/*')) {
            $requestId = $request->attributes->get('correlation_id');
            $message = $exception->getMessage();
            $code = str_contains($message, 'no longer available') ? 'OUT_OF_STOCK' : 'VALIDATION';

            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'fields' => (object) $exception->errors(),
                    'requestId' => is_string($requestId) ? $requestId : '',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY)->header('Cache-Control', 'no-store, private');
        }

        return redirect()->back()->withErrors($exception->errors());
    }
}
