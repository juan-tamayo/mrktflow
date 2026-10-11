<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(SecurityHeaders::class);
        $middleware->preventRequestForgery();
        $middleware->trustHosts(
            at: fn () => ['^'.preg_quote((string) parse_url(config()->string('app.url'), PHP_URL_HOST), '/').'$'],
            subdomains: false,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(fn (Response $response) => SecurityHeaders::apply(request(), $response));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
