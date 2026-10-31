<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\Catalog\Actions\SearchCatalog;
use App\Domain\Catalog\Contracts\CatalogReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class HomeController
{
    /**
     * The shop homepage. Until installation completes the root keeps its
     * long-standing contract: the closed page with a 503.
     */
    public function show(SearchCatalog $search, CatalogReader $catalog): View|Response
    {
        if (DB::table('installations')->where('id', 1)->value('status') !== 'complete') {
            return response()->view('storefront.closed', [], 503)->header('Retry-After', '3600');
        }

        $result = $search->search(['page_size' => SearchCatalog::DEFAULT_PAGE_SIZE]);
        $products = [];
        foreach ($result['products'] as $product) {
            $products[] = [
                'id' => $product['id'],
                'url' => '/products/'.$product['slug'],
                'title' => $product['title'],
                'available' => $product['available'],
                'availability' => $product['availability'],
                'image' => $product['image'],
                'money' => $this->money($product['money']),
            ];
        }

        $categories = [];
        foreach ($result['categories'] as $category) {
            if (str_contains($category['path'], '/')) {
                continue;
            }
            $snapshot = $catalog->publicCategory($category['path']);
            $categories[] = [
                'path' => $category['path'],
                'title' => $category['title'],
                'description' => $snapshot['description'],
                'count' => count($snapshot['products']),
            ];
        }

        $featured = null;
        $featuredSlug = null;
        foreach ($result['products'] as $product) {
            if ($product['slug'] === 'cafe-de-altura') {
                $featuredSlug = $product['slug'];
                break;
            }
        }
        $featuredSlug ??= $result['products'][0]['slug'] ?? null;
        if ($featuredSlug !== null) {
            $snapshot = $catalog->publicProduct($featuredSlug);
            $featured = [
                'url' => '/products/'.$snapshot['slug'],
                'title' => $snapshot['title'],
                'description' => $snapshot['description'],
                'image' => $snapshot['image'],
                'money' => $this->money($snapshot['variant']['money']),
            ];
        }

        return view('default-theme::home', [
            'products' => $products,
            'categories' => $categories,
            'featured' => $featured,
        ]);
    }

    /**
     * @param  array{amount: int, currency: string, exponent: int}  $money
     * @return array{amountMinor: int, currency: string, exponent: int, formatted: string}
     */
    private function money(array $money): array
    {
        $amount = $money['amount'];
        $exponent = $money['exponent'];
        $scale = 10 ** $exponent;
        $fraction = $exponent === 0 ? '' : '.'.str_pad((string) ($amount % $scale), $exponent, '0', STR_PAD_LEFT);

        return [
            'amountMinor' => $amount,
            'currency' => $money['currency'],
            'exponent' => $exponent,
            'formatted' => $money['currency'].' '.intdiv($amount, $scale).$fraction,
        ];
    }
}
