<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CartResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $cart = $this->resource;
        if (! is_array($cart)) {
            return [];
        }
        $lines = [];
        foreach ($cart['lines'] as $line) {
            $lines[] = [
                'id' => $line['id'],
                'variantId' => $line['variant_id'],
                'productId' => $line['product_id'],
                'title' => $line['title'],
                'sku' => $line['sku'],
                'type' => $line['type'],
                'quantity' => $line['quantity'],
                'available' => $line['available'],
                'availability' => $line['availability'],
                'maxQuantity' => $line['max_quantity'],
                'money' => $line['money'],
                'lineTotal' => $line['line_total'],
            ];
        }

        return [
            'id' => $cart['id'],
            'revision' => $cart['revision'],
            'lines' => $lines,
            'subtotal' => $cart['subtotal'],
            'notices' => $cart['notices'],
        ];
    }
}
