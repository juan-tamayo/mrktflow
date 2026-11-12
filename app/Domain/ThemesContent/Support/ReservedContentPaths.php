<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Support;

use App\Domain\Catalog\Support\ReservedCatalogSlugs;

/**
 * THEME-05 path rules for pages, articles and menu links (T065).
 *
 * Content slugs cannot reuse reserved storefront/system first segments or an
 * existing category-tree path. Menu URLs must be local paths; administration
 * and installer namespaces are refused so navigation cannot point at them.
 */
final class ReservedContentPaths
{
    /** First segments that must never appear as a storefront menu destination. */
    private const FORBIDDEN_MENU_SEGMENTS = [
        'admin', 'setup', 'staff', 'storage', 'up', 'brand', 'build',
        'webhooks', 'store-api', 'livewire', 'downloads', 'login', 'register',
    ];

    /** @return list<string> */
    public static function slugs(): array
    {
        return ReservedCatalogSlugs::all();
    }

    public static function isReservedSlug(string $slug): bool
    {
        return in_array($slug, self::slugs(), true);
    }

    public static function isLocalPath(string $value): bool
    {
        return strlen($value) <= 500
            && preg_match('#^/(?!/)[A-Za-z0-9/_.-]*$#D', $value) === 1
            && array_intersect(explode('/', $value), ['.', '..']) === [];
    }

    public static function isAllowedMenuUrl(string $url): bool
    {
        if ($url === '/') {
            return true;
        }
        if (! self::isLocalPath($url)) {
            return false;
        }
        $segments = array_values(array_filter(explode('/', $url), static fn (string $segment): bool => $segment !== ''));
        $first = $segments[0] ?? '';

        return $first !== '' && ! in_array($first, self::FORBIDDEN_MENU_SEGMENTS, true);
    }
}
