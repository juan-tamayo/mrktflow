<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\Catalog\Actions\SearchCatalog;
use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\Catalog\Data\ProductView;
use App\Domain\Catalog\Exceptions\CatalogProductNotFound;
use App\Domain\Catalog\Exceptions\CatalogProductUnpublished;
use App\Http\Resources\ProductResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreApiProductController
{
    public function show(string $id, CatalogReader $catalog): JsonResponse
    {
        try {
            $view = ProductView::fromPublic($catalog->publicProduct($id));
        } catch (CatalogProductNotFound|CatalogProductUnpublished) {
            return $this->notFound();
        }

        return (new ProductResource($view))->response();
    }

    public function index(Request $request, SearchCatalog $search): JsonResponse
    {
        $result = $search->search($request->query->all());
        $products = [];
        foreach ($result['products'] as $product) {
            $products[] = [
                'id' => $product['id'],
                'slug' => $product['slug'],
                'url' => '/products/'.$product['slug'],
                'title' => $product['title'],
                'available' => $product['available'],
                'availability' => $product['availability'],
                'image' => $product['image'],
                'money' => $product['money'],
            ];
        }

        return response()->json([
            'data' => $products,
            'meta' => $result['meta'],
        ]);
    }

    private function notFound(): JsonResponse
    {
        $requestId = request()->attributes->get('correlation_id');

        return response()->json([
            'error' => [
                'code' => 'NOT_FOUND',
                'message' => 'This product is not available.',
                'fields' => (object) [],
                'requestId' => is_string($requestId) ? $requestId : '',
            ],
        ], 404);
    }
}
