<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\ThemesContent\Data\ProductView;
use App\Domain\ThemesContent\Rendering\DefaultThemeRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;

final class ThemePreviewController
{
    public function show(Request $request, DefaultThemeRenderer $renderer): View
    {
        Gate::authorize('content.read');
        $json = file_get_contents(base_path('themes/default/preview/product.json'));
        if ($json === false || strlen($json) > 65536) {
            throw new RuntimeException('Theme preview data is unavailable.');
        }
        $input = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($input)) {
            throw new RuntimeException('Theme preview data is invalid.');
        }
        $product = ProductView::from($input);
        $selection = $request->validate(['variant' => ['nullable', 'string', Rule::in(array_column($product->variants, 'id'))]]);

        return $renderer->product($product, $selection['variant'] ?? $product->variants[0]['id']);
    }
}
