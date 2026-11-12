<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Rendering;

use App\Domain\ThemesContent\Data\CategoryView;
use App\Domain\ThemesContent\Data\ProductView;
use App\Domain\ThemesContent\Data\SearchView;
use App\Domain\ThemesContent\Support\VisualTemplates;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;

final class DefaultThemeRenderer
{
    /** @param list<array{title: string, url: string}> $breadcrumbs */
    public function product(ProductView $product, string $selectedId, bool $preview = true, array $breadcrumbs = [], string $selectionState = 'selected', int $cartRevision = 1, int $cartItemCount = 0, string $templateKey = 'product.default'): View
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
        if (! in_array($selectionState, ['selected', 'missing', 'unavailable', 'out_of_stock'], true)) {
            throw new InvalidArgumentException('Unknown variant selection state.');
        }
        if ($cartRevision < 1) {
            throw new InvalidArgumentException('Unknown cart revision.');
        }
        if ($cartItemCount < 0) {
            throw new InvalidArgumentException('Unknown cart item count.');
        }

        return view(VisualTemplates::view('product', $templateKey), [
            'product' => $product,
            'selected' => $selected,
            'preview' => $preview,
            'breadcrumbs' => $breadcrumbs,
            'selectionState' => $selectionState,
            'cartRevision' => $cartRevision,
            'cartItemCount' => $cartItemCount,
            'assignedTemplateKey' => $templateKey,
        ]);
    }

    /**
     * @param  array{id: ?string, revision: int, lines: list<array<string, mixed>>, subtotal: array{amount: int, currency: string, exponent: int, formatted: string}, notices: list<string>}  $cart
     * @param  array<string, mixed>  $payload
     */
    public function cart(array $cart, array $payload = []): View
    {
        if ($cart['revision'] < 1) {
            throw new InvalidArgumentException('Unknown cart revision.');
        }
        $itemCount = 0;
        foreach ($cart['lines'] as $line) {
            $itemCount += (int) $line['quantity'];
        }

        return view('default-theme::cart', ['cart' => $cart, 'payload' => $payload, 'cartItemCount' => $itemCount]);
    }

    /**
     * @param  array{id: ?string, revision: int, lines: list<array<string, mixed>>, subtotal: array{amount: int, currency: string, exponent: int, formatted: string}, notices: list<string>}  $cart
     * @param  array<string, mixed>|null  $quote
     * @param  list<array{code: string, label: string}>  $countries
     * @param  array<string, mixed>  $payload
     */
    public function checkout(array $cart, ?array $quote, array $countries, string $email = '', array $payload = []): View
    {
        if ($cart['revision'] < 1) {
            throw new InvalidArgumentException('Unknown cart revision.');
        }
        $itemCount = 0;
        foreach ($cart['lines'] as $line) {
            $itemCount += (int) $line['quantity'];
        }

        return view('default-theme::checkout', [
            'cart' => $cart,
            'quote' => $quote,
            'countries' => $countries,
            'email' => $email,
            'payload' => $payload,
            'cartItemCount' => $itemCount,
        ]);
    }

    public function category(CategoryView $category, string $templateKey = 'category.default'): View
    {
        return view(VisualTemplates::view('category', $templateKey), [
            'category' => $category,
            'assignedTemplateKey' => $templateKey,
        ]);
    }

    public function search(SearchView $search): View
    {
        return view('default-theme::search', ['search' => $search]);
    }
}
