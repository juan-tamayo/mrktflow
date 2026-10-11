<?php

use App\Http\Controllers\Admin\InstallController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Middleware;

Route::get('/', fn () => response()->view('storefront.closed', [], 503)->header('Retry-After', '3600'));

Route::get('/admin', function (Request $request) {
    return Inertia::render('AdminUnavailable')->toResponse($request)->setStatusCode(503);
})->middleware(Middleware::class);

Route::middleware(Middleware::class)->group(function (): void {
    Route::get('/setup', [InstallController::class, 'show'])->name('setup');
    Route::post('/setup/access', [InstallController::class, 'unlock'])->middleware('throttle:5,1');
    Route::post('/setup/settings', [InstallController::class, 'save'])->middleware('throttle:30,1');
});
