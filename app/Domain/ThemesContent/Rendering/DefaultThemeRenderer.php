<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Rendering;

use App\Domain\ThemesContent\Data\ProductView;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;

final class DefaultThemeRenderer
{
    public function product(ProductView $product, string $selectedId): View
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

        return view('default-theme::product', ['product' => $product, 'selected' => $selected]);
    }
}
