<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Support;

/**
 * Storefront route and visual-template keys a theme must cover (THEME-06, T068).
 *
 * Registration can record a valid theme that still cannot be activated:
 * activation requires templates for every SystemSlots page plus layout
 * and the CMS page fallback, and it names missing keys instead of
 * inventing a substitute.
 */
final class ThemeCoverage
{
    /** @var array<string, list<string>> Route or surface => acceptable template keys */
    private const REQUIRED = [
        'layout' => ['layout'],
        'home' => ['home'],
        'category' => ['category.default', 'category'],
        'search' => ['search'],
        'product' => ['product.default', 'product'],
        'compare' => ['compare'],
        'wishlist' => ['wishlist'],
        'cart' => ['cart'],
        'checkout' => ['checkout'],
        'payment-status' => ['payment.status'],
        'order-status' => ['order.status'],
        'account-orders' => ['account.orders'],
        'account-order' => ['account.order'],
        'account-downloads' => ['account.downloads'],
        'download-grant' => ['download.grant'],
        'preferences' => ['account.preferences'],
        'page' => ['page.default', 'page'],
    ];

    /** @return list<string> */
    public static function surfaces(): array
    {
        return array_keys(self::REQUIRED);
    }

    /**
     * @param  list<string>  $templateKeys
     * @return list<string>
     */
    public static function missingSurfaces(array $templateKeys): array
    {
        $declared = array_fill_keys($templateKeys, true);
        $missing = [];
        foreach (self::REQUIRED as $surface => $keys) {
            $covered = false;
            foreach ($keys as $key) {
                if (isset($declared[$key])) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $missing[] = $surface;
            }
        }

        return $missing;
    }

    public static function family(string $templateKey): string
    {
        $dot = strpos($templateKey, '.');
        if ($dot === false) {
            return $templateKey;
        }

        return substr($templateKey, 0, $dot);
    }
}
