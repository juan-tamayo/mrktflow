<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\Catalog\Actions\SearchCatalog;
use App\Domain\ThemesContent\Data\SearchView;
use App\Domain\ThemesContent\Rendering\DefaultThemeRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class SearchController
{
    public function show(Request $request, SearchCatalog $search, DefaultThemeRenderer $renderer): View
    {
        $result = $search->search($request->query->all());
        $products = [];
        foreach ($result['products'] as $product) {
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

        return $renderer->search(SearchView::from([
            'query' => $result['query'],
            'products' => $products,
            'meta' => $result['meta'],
            'facets' => $result['facets'],
            'categories' => $result['categories'],
            'sorts' => $result['sorts'],
        ]));
    }
}
