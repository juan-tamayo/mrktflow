<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Support;

use App\Domain\CheckoutOrders\Actions\ReadCart;
use App\Domain\CheckoutOrders\Support\CartOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Public catalog/content may be stored by a shared cache. Private, preview,
 * checkout, account and personalized (cart/session) responses never are.
 */
final class PublicCachePolicy
{
    public static function apply(Request $request, Response $response): void
    {
        $robots = self::robotsFor($request);
        if ($response->getStatusCode() >= 400 || self::mustNotShare($request)) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Robots-Tag', $robots);

            return;
        }

        $response->headers->set('Cache-Control', 'public, max-age=60, s-maxage=60');
        $response->headers->set('X-Robots-Tag', $robots);
    }

    public static function isIndexablePublicPath(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');
        if ($path === '/') {
            return false;
        }

        return (bool) preg_match('#^/(products|categories|pages|journal)(/|$)#D', $path);
    }

    private static function robotsFor(Request $request): string
    {
        return self::isIndexablePublicPath($request) ? 'index, follow' : 'noindex, nofollow';
    }

    private static function mustNotShare(Request $request): bool
    {
        if (Auth::guard('customer')->check() || Auth::guard('staff')->check()) {
            return true;
        }
        $path = '/'.ltrim($request->path(), '/');
        if ($path === '/' || $path === '/search' || $path === '/sitemap.xml') {
            return $path !== '/sitemap.xml';
        }
        if (preg_match('#^/(admin|setup|staff|account|cart|checkout|orders|wishlist|compare|watch|preferences)(/|$)#D', $path) === 1) {
            return true;
        }
        if (! self::isIndexablePublicPath($request)) {
            return true;
        }

        return self::hasCartLines($request);
    }

    private static function hasCartLines(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }
        $token = $request->session()->get('guest_identity');
        if (! is_string($token) || $token === '' || strlen($token) > 128) {
            return false;
        }
        try {
            if (DB::table('installations')->where('id', 1)->value('status') !== 'complete') {
                return false;
            }
            $cart = app(ReadCart::class)->snapshot(CartOwner::guest($token));

            return $cart['lines'] !== [];
        } catch (Throwable) {
            return true;
        }
    }
}
