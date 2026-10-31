<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\Catalog\Support\VariantOptions;
use App\Domain\CheckoutOrders\Actions\ReadCart;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\ShoppingTools\Actions\CompareProducts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Product comparison for shoppers (SHOP-05, SHOP-06).
 *
 * The endpoint is a pure read: selections arrive as `items` (variant
 * ids) and `products` (product ids) query lists and the typed read
 * model decides what can be compared, what was removed and what had
 * to be omitted. The page carries no shopper state, but validation
 * failures use the same generic envelope as the other shopping-tools
 * surfaces so an unpublished selection is indistinguishable from an
 * unknown one. The HTML surface also carries the table UI's working
 * data (T059): the replace candidates — every published product
 * resolved to its default listed variant, so a draft can never be
 * offered as a replacement — and the cart revision its add-to-cart
 * forms post against. The JSON surface stays the pure read model.
 */
final class ComparisonController
{
    public function show(Request $request, CompareProducts $compare): JsonResponse|View
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 503);
        $selections = [];
        foreach ($this->idList($request, 'items') as $variantId) {
            $selections[] = ['variant_id' => $variantId];
        }
        foreach ($this->idList($request, 'products') as $productId) {
            $selections[] = ['product_id' => $productId];
        }
        try {
            $comparison = $compare->compare($selections)->jsonSerialize();
        } catch (ValidationException $exception) {
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

            return view('default-theme::compare', [
                'comparison' => null,
                'error' => $exception->getMessage(),
                'candidates' => [],
                'cartRevision' => $this->cartRevision($request),
            ]);
        }
        if ($request->expectsJson() || $request->is('store-api/*')) {
            return response()->json($comparison)->header('Cache-Control', 'no-store, private');
        }

        return view('default-theme::compare', [
            'comparison' => $comparison,
            'error' => null,
            'candidates' => $this->candidates(),
            'cartRevision' => $this->cartRevision($request),
        ]);
    }

    /**
     * Replace candidates for the table UI: one entry per published
     * product, resolved to its default listed variant exactly as the
     * read model resolves a product selection.
     *
     * @return list<array{variant_id: string, product_id: string, title: string, variant_label: string}>
     */
    private function candidates(): array
    {
        $rows = DB::table('product_variants')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->where('products.status', 'published')
            ->where('product_variants.available', true)
            ->orderBy('products.title')
            ->orderByDesc('product_variants.is_default')
            ->orderBy('product_variants.created_at')
            ->orderBy('product_variants.id')
            ->select([
                'product_variants.id as variant_id',
                'product_variants.product_id',
                'products.title',
                'product_variants.options',
            ])
            ->limit(400)
            ->get();
        $candidates = [];
        $seen = [];
        foreach ($rows as $row) {
            $data = get_object_vars($row);
            $productId = $data['product_id'] ?? null;
            $variantId = $data['variant_id'] ?? null;
            $title = $data['title'] ?? null;
            if (! is_string($productId) || ! is_string($variantId) || ! is_string($title)) {
                continue;
            }
            if (isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;
            $decoded = json_decode(is_string($data['options'] ?? null) ? $data['options'] : '[]', true);
            $options = [];
            if (is_array($decoded)) {
                foreach ($decoded as $option) {
                    if (is_array($option) && is_string($option['name'] ?? null) && is_string($option['value'] ?? null)) {
                        $options[] = ['name' => $option['name'], 'value' => $option['value']];
                    }
                }
            }
            $candidates[] = [
                'variant_id' => $variantId,
                'product_id' => $productId,
                'title' => $title,
                'variant_label' => VariantOptions::label($options),
            ];
            if (count($candidates) >= 100) {
                break;
            }
        }

        return $candidates;
    }

    private function cartRevision(Request $request): int
    {
        $cart = app(ReadCart::class)->snapshot(CartOwner::fromRequest($request));

        return $cart['revision'];
    }

    /** @return list<string> */
    private function idList(Request $request, string $key): array
    {
        $raw = $request->query($key);
        if (is_string($raw) && $raw !== '') {
            $raw = [$raw];
        }
        if (! is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            if (is_string($value) && $value !== '') {
                $ids[] = $value;
            }
        }

        return $ids;
    }
}
