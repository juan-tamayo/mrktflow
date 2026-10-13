<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\Catalog\Exceptions\CatalogCategoryNotFound;
use App\Domain\Catalog\Exceptions\CatalogCategoryUnpublished;
use App\Domain\Catalog\Exceptions\CatalogProductNotFound;
use App\Domain\Catalog\Exceptions\CatalogProductUnpublished;
use App\Domain\ThemesContent\Data\CategoryView;
use App\Domain\ThemesContent\Data\ProductView;
use App\Domain\ThemesContent\Rendering\DefaultThemeRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CatalogController
{
    public function product(Request $request, string $slug, CatalogReader $catalog, DefaultThemeRenderer $renderer): View
    {
        try {
            $snapshot = $catalog->publicProduct($slug);
        } catch (CatalogProductNotFound|CatalogProductUnpublished) {
            abort(404);
        }
        $variants = [];
        foreach ($snapshot['variants'] as $variant) {
            $variants[] = [
                'id' => $variant['id'],
                'label' => $variant['label'],
                'available' => $variant['available'],
                'options' => $variant['options'],
                'image' => $variant['image'],
                'money' => [
                    'amountMinor' => $variant['money']['amount'],
                    'currency' => $variant['money']['currency'],
                    'exponent' => $variant['money']['exponent'],
                ],
            ];
        }
        $default = $snapshot['variants'][0];
        foreach ($snapshot['variants'] as $variant) {
            if ($variant['isDefault']) {
                $default = $variant;
                break;
            }
        }
        $requested = $request->query('variant');
        $selectionState = 'selected';
        $selectedId = $default['id'];
        if (! is_string($requested) || $requested === '') {
            $selectionState = $snapshot['option_axes'] === [] ? 'selected' : 'missing';
        } else {
            $match = null;
            foreach ($snapshot['variants'] as $variant) {
                if ($variant['id'] === $requested) {
                    $match = $variant;
                    break;
                }
            }
            abort_if($match === null, 422);
            $selectedId = $match['id'];
            $selectionState = $match['available'] ? 'selected' : 'unavailable';
        }
        $selected = $default;
        foreach ($snapshot['variants'] as $variant) {
            if ($variant['id'] === $selectedId) {
                $selected = $variant;
                break;
            }
        }
        $product = ProductView::from([
            'id' => $snapshot['id'],
            'url' => '/products/'.$snapshot['slug'],
            'title' => $snapshot['title'],
            'description' => $snapshot['description'],
            'image' => $selectionState === 'selected' ? $selected['image'] : $snapshot['image'],
            'variants' => $variants,
            'optionAxes' => $snapshot['option_axes'],
            'attributes' => array_merge([
                ['label' => 'SKU', 'value' => $selected['sku']],
                ['label' => 'Delivery', 'value' => $snapshot['type'] === 'digital' ? 'Digital download' : 'Physical product'],
            ], $snapshot['type'] === 'digital' ? array_merge(
                [['label' => 'Per-order quantity', 'value' => 'Quantity one']],
                array_map(fn (array $file): array => ['label' => 'Download', 'value' => $file['label']], $selected['digital_files'] ?? []),
            ) : [], $snapshot['typed_attributes']),
        ]);

        return $renderer->product($product, $selectedId, false, $snapshot['breadcrumbs'], $selectionState);
    }

    public function category(string $path, CatalogReader $catalog, DefaultThemeRenderer $renderer): View|RedirectResponse
    {
        if (! preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*){0,4}$#D', $path)) {
            abort(404);
        }
        $redirect = DB::table('category_redirects')->where('from_path', $path)->first();
        if ($redirect !== null) {
            try {
                $catalog->publicCategory((string) $redirect->to_path);
            } catch (CatalogCategoryNotFound|CatalogCategoryUnpublished) {
                abort(404);
            }

            return redirect('/categories/'.$redirect->to_path, 301);
        }
        try {
            $snapshot = $catalog->publicCategory($path);
        } catch (CatalogCategoryNotFound|CatalogCategoryUnpublished) {
            abort(404);
        }
        $products = [];
        foreach ($snapshot['products'] as $product) {
            $products[] = [
                'id' => $product['id'],
                'url' => '/products/'.$product['slug'],
                'title' => $product['title'],
                'image' => $product['image'],
                'money' => [
                    'amountMinor' => $product['money']['amount'],
                    'currency' => $product['money']['currency'],
                    'exponent' => $product['money']['exponent'],
                ],
            ];
        }

        return $renderer->category(CategoryView::from([
            'id' => $snapshot['id'],
            'url' => '/categories/'.$snapshot['path'],
            'title' => $snapshot['title'],
            'description' => $snapshot['description'],
            'breadcrumbs' => $snapshot['breadcrumbs'],
            'products' => $products,
        ]));
    }
}
