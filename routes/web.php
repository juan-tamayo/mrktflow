<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Middleware;

Route::get('/', fn () => response()->view('storefront.closed', [], 503)->header('Retry-After', '3600'));

Route::get('/admin', function (Request $request) {
    return Inertia::render('AdminUnavailable')->toResponse($request)->setStatusCode(503);
})->middleware(Middleware::class);
