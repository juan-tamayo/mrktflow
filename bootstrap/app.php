<?php

use App\Http\Middleware\RequestCorrelation;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware([])->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([RequestCorrelation::class]);
        // SecurityHeaders is appended, not prepended: its HTTPS check reads
        // $request->isSecure(), which is only true once TrustProxies (in the
        // framework's default stack) has applied the forwarded-proto trust.
        // Prepended ahead of TrustProxies it would reject every proxied
        // request in production.
        $middleware->append([SecurityHeaders::class]);
        // The signed one-click unsubscribe POST is the single deliberate
        // CSRF exception: it is cookie-free and gated solely by its
        // purpose-scoped HMAC signature (PLAT-07). Every other state
        // change keeps full request-forgery protection.
        $middleware->preventRequestForgery(except: ['preferences/unsubscribe']);
        $middleware->trimStrings(except: ['owner.password', 'owner.password_confirmation', 'customer.password', 'customer.password_confirmation', 'password_reset.password', 'password_reset.password_confirmation', 'profile.current_password']);
        $middleware->trustHosts(
            at: fn () => ['^'.preg_quote((string) parse_url(config()->string('app.url'), PHP_URL_HOST), '/').'$'],
            subdomains: false,
        );
        // Deployment topology (selected for the demo deployment): a single
        // reverse proxy (Caddy) on the same Docker network is the only
        // ingress; the application port is never published directly.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception): void {
            Log::error('application.failed', [
                'correlation_id' => request()->attributes->get('correlation_id') ?? Context::get('correlation_id'),
                'exception_class' => $exception::class,
                'source_file' => basename($exception->getFile()),
                'source_line' => $exception->getLine(),
            ]);
        })->stop();
        $exceptions->dontFlash(['setup_secret', 'settings', 'owner', 'invitation_token', 'customer', 'challenge_token', 'password_reset', 'profile', 'address', 'code', 'product', 'category', 'variants', 'options', 'schema', 'on_hand', 'operation_key', 'variant_id', 'tracks_inventory', 'low_stock_threshold', 'expected_revision', 'quantity', 'email', 'billing', 'shipping', 'coupon', 'fingerprint', 'policy_acknowledged', 'note']);
        $exceptions->respond(fn (Response $response) => SecurityHeaders::apply(request(), $response));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
