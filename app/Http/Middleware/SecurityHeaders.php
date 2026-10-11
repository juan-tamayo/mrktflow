<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = bin2hex(random_bytes(16));
        $request->attributes->set('csp_nonce', $nonce);
        Vite::useCspNonce($nonce);

        if (! app()->environment('local', 'testing') && ! $request->isSecure()) {
            abort(400, 'HTTPS is required.');
        }

        return self::apply($request, $next($request));
    }

    public static function apply(Request $request, Response $response): Response
    {
        $nonce = $request->attributes->get('csp_nonce');
        if (! is_string($nonce)) {
            $nonce = bin2hex(random_bytes(16));
        }

        $development = app()->environment('local') && Vite::isRunningHot();
        $scripts = "'self' 'nonce-{$nonce}'".($development ? ' http://127.0.0.1:5173' : '');
        $connect = "'self'".($development ? ' http://127.0.0.1:5173 ws://127.0.0.1:5173' : '');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src {$scripts}; style-src 'self' 'nonce-{$nonce}'; connect-src {$connect}; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        if (! app()->environment('local', 'testing') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
