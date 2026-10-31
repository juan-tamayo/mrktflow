<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Support;

use InvalidArgumentException;

/**
 * The storefront slot contract (WEB-01, WEB-02, THEME-01).
 *
 * Every themed page renders through one layout (`default-theme::layout`)
 * whose named slots sit at platform-defined positions. A slot is one of:
 *
 * - `theme` — the theme supplies the content (the page template itself in
 *   `content`, extra head markup in `head`, a banner in `announcement`).
 * - `platform` — the platform renders the component and a theme can never
 *   supply or replace it through composition. The chrome slots (`masthead`,
 *   `footer`) are platform-positioned: the layout includes them, the theme
 *   only styles them. The main-region platform slots are the commerce
 *   actions — checkout summary and accept/pay forms, the product purchase
 *   panel, cart line actions, download issue/start — and they resolve from
 *   the platform's own view location (`storefront.slots.*`), never from a
 *   theme namespace, so a file planted in a theme directory cannot shadow
 *   them. This is the template-contracts rule in code: core owns checkout,
 *   payment and download authorization; themes supply documented visual
 *   slots and tokens around those screens.
 */
final class SystemSlots
{
    /** @var array<string, string> Page key => the page's theme template. */
    private const TEMPLATES = [
        'home' => 'default-theme::home',
        'category' => 'default-theme::category',
        'search' => 'default-theme::search',
        'product' => 'default-theme::product',
        'compare' => 'default-theme::compare',
        'wishlist' => 'default-theme::wishlist',
        'cart' => 'default-theme::cart',
        'checkout' => 'default-theme::checkout',
        'payment-status' => 'default-theme::payment-status',
        'order-status' => 'default-theme::order-status',
        'account-orders' => 'default-theme::account-orders',
        'account-order' => 'default-theme::account-order',
        'account-downloads' => 'default-theme::account-downloads',
        'download-grant' => 'default-theme::download-grant',
        'preferences' => 'default-theme::preferences',
    ];

    /** @var array<string, list<array{name: string, view: string}>> Page key => its platform commerce slots, in render order. */
    private const COMMERCE_SLOTS = [
        'product' => [
            ['name' => 'product.purchase', 'view' => 'storefront.slots.product-purchase'],
        ],
        'cart' => [
            ['name' => 'cart.line-actions', 'view' => 'storefront.slots.cart-line-actions'],
        ],
        'checkout' => [
            ['name' => 'checkout.summary', 'view' => 'storefront.slots.checkout-summary'],
            ['name' => 'checkout.actions', 'view' => 'storefront.slots.checkout-actions'],
        ],
        'account-downloads' => [
            ['name' => 'downloads.issue', 'view' => 'storefront.slots.download-issue'],
        ],
        'download-grant' => [
            ['name' => 'download.start', 'view' => 'storefront.slots.download-start'],
        ],
    ];

    /** @return list<string> */
    public static function pages(): array
    {
        return array_keys(self::TEMPLATES);
    }

    public static function template(string $page): string
    {
        if (! isset(self::TEMPLATES[$page])) {
            throw new InvalidArgumentException("Unknown storefront page [{$page}].");
        }

        return self::TEMPLATES[$page];
    }

    /**
     * The full slot list for a page: the slots every page carries, then the
     * page's own commerce slots.
     *
     * @return list<array{name: string, kind: string, region: string, view: ?string}>
     */
    public static function forPage(string $page): array
    {
        $slots = [
            ['name' => 'head', 'kind' => 'theme', 'region' => 'head', 'view' => null],
            ['name' => 'announcement', 'kind' => 'theme', 'region' => 'announcement', 'view' => null],
            ['name' => 'masthead', 'kind' => 'platform', 'region' => 'masthead', 'view' => 'default-theme::partials.masthead'],
            ['name' => 'content', 'kind' => 'theme', 'region' => 'main', 'view' => self::template($page)],
            ['name' => 'footer', 'kind' => 'platform', 'region' => 'footer', 'view' => 'default-theme::partials.footer'],
        ];
        foreach (self::COMMERCE_SLOTS[$page] ?? [] as $slot) {
            $slots[] = ['name' => $slot['name'], 'kind' => 'platform', 'region' => 'main', 'view' => $slot['view']];
        }

        return $slots;
    }
}
