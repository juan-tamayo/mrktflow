<?php

use App\Http\Middleware\RequestCorrelation;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([RequestCorrelation::class, SecurityHeaders::class]);
        $middleware->preventRequestForgery();
        $middleware->trimStrings(except: ['owner.password', 'owner.password_confirmation', 'customer.password', 'customer.password_confirmation', 'password_reset.password', 'password_reset.password_confirmation', 'profile.current_password']);
        $middleware->trustHosts(
            at: fn () => ['^'.preg_quote((string) parse_url(config()->string('app.url'), PHP_URL_HOST), '/').'$'],
            subdomains: false,
        );
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
        $exceptions->dontFlash(['setup_secret', 'settings', 'owner', 'invitation_token', 'customer', 'challenge_token', 'password_reset', 'profile', 'address', 'code', 'product', 'category', 'variants', 'options']);
        $exceptions->respond(fn (Response $response) => SecurityHeaders::apply(request(), $response));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
