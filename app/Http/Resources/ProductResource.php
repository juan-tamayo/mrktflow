<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalog\Data\ProductView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductView */
final class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        if (! $product instanceof ProductView) {
            return [];
        }

        return $product->jsonSerialize();
    }
}
