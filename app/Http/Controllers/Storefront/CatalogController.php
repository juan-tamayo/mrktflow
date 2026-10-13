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
use Illuminate\Support\Facades\DB;

final class CatalogController
{
    public function product(string $slug, CatalogReader $catalog, DefaultThemeRenderer $renderer): View
    {
        try {
            $snapshot = $catalog->publicProduct($slug);
        } catch (CatalogProductNotFound|CatalogProductUnpublished) {
            abort(404);
        }
        $product = ProductView::from([
            'id' => $snapshot['id'],
            'url' => '/products/'.$snapshot['slug'],
            'title' => $snapshot['title'],
            'description' => $snapshot['description'],
            'image' => $snapshot['image'],
            'variants' => [[
                'id' => $snapshot['variant']['id'],
                'label' => 'Standard',
                'money' => [
                    'amountMinor' => $snapshot['variant']['money']['amount'],
                    'currency' => $snapshot['variant']['money']['currency'],
                    'exponent' => $snapshot['variant']['money']['exponent'],
                ],
            ]],
            'attributes' => [
                ['label' => 'SKU', 'value' => $snapshot['variant']['sku']],
                ['label' => 'Delivery', 'value' => 'Physical product'],
            ],
        ]);

        return $renderer->product($product, $snapshot['variant']['id'], false, $snapshot['breadcrumbs']);
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
