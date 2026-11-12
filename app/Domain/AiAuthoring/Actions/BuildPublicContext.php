<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Actions;

use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\ThemesContent\Data\ProductView;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class BuildPublicContext
{
    public function __construct(private CatalogReader $catalog) {}

    /**
     * @param  array<array-key, mixed>  $productIds
     * @return list<ProductView>
     */
    public function products(array $productIds): array
    {
        if (! array_is_list($productIds) || count($productIds) > 20) {
            throw new InvalidArgumentException('Select at most 20 published products.');
        }
        $views = [];
        $seen = [];
        foreach ($productIds as $id) {
            if (! is_string($id) || ! Str::isUuid($id) || $id !== strtolower($id) || in_array($id, $seen, true)) {
                throw new InvalidArgumentException('Authoring requires distinct published product IDs.');
            }
            $seen[] = $id;
            $views[] = $this->view($this->catalog->publicProduct($id));
        }

        return $views;
    }

    /** @param array<string, mixed> $snapshot */
    private function view(array $snapshot): ProductView
    {
        $variants = [];
        $rows = is_array($snapshot['variants'] ?? null) ? $snapshot['variants'] : [];
        foreach ($rows as $variant) {
            if (! is_array($variant) || ! is_array($variant['money'] ?? null) || ! is_array($variant['image'] ?? null)) {
                throw new InvalidArgumentException('Public product context is incomplete.');
            }
            $variants[] = [
                'id' => $variant['id'],
                'label' => $variant['label'],
                'available' => $variant['available'],
                'availability' => $variant['availability'],
                'options' => $variant['options'] ?? [],
                'image' => $variant['image'],
                'money' => [
                    'amountMinor' => $variant['money']['amount'] ?? null,
                    'currency' => $variant['money']['currency'] ?? null,
                    'exponent' => $variant['money']['exponent'] ?? null,
                ],
            ];
        }
        $slug = is_string($snapshot['slug'] ?? null) ? $snapshot['slug'] : '';

        return ProductView::from([
            'id' => $snapshot['id'] ?? null,
            'url' => '/products/'.$slug,
            'title' => $snapshot['title'] ?? null,
            'description' => $snapshot['description'] ?? null,
            'image' => $snapshot['image'] ?? null,
            'variants' => $variants,
            'attributes' => $snapshot['typed_attributes'] ?? [],
            'optionAxes' => $snapshot['option_axes'] ?? [],
        ]);
    }
}
