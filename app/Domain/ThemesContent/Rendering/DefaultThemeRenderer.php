<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Rendering;

use App\Domain\ThemesContent\Data\CategoryView;
use App\Domain\ThemesContent\Data\ProductView;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;

final class DefaultThemeRenderer
{
    /** @param list<array{title: string, url: string}> $breadcrumbs */
    public function product(ProductView $product, string $selectedId, bool $preview = true, array $breadcrumbs = []): View
    {
        $selected = null;
        foreach ($product->variants as $variant) {
            if ($variant['id'] === $selectedId) {
                $selected = $variant;
                break;
            }
        }
        if ($selected === null) {
            throw new InvalidArgumentException('Select a supplied product variant.');
        }

        return view('default-theme::product', [
            'product' => $product,
            'selected' => $selected,
            'preview' => $preview,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    public function category(CategoryView $category): View
    {
        return view('default-theme::category', ['category' => $category]);
    }
}
