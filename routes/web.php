<?php

use App\Http\Controllers\Admin\InstallController;
use App\Http\Controllers\Admin\StaffSessionController;
use App\Http\Middleware\RequireStaffSession;
use Illuminate\Support\Facades\Route;
use Inertia\Middleware;

Route::get('/', fn () => response()->view('storefront.closed', [], 503)->header('Retry-After', '3600'));

Route::middleware(Middleware::class)->group(function (): void {
    Route::get('/admin/login', [StaffSessionController::class, 'show'])->name('staff.login');
    Route::post('/admin/login', [StaffSessionController::class, 'login'])->middleware('throttle:staff-login');
    Route::middleware(RequireStaffSession::class)->group(function (): void {
        Route::get('/admin', [StaffSessionController::class, 'home']);
        Route::post('/admin/logout', [StaffSessionController::class, 'logout']);
    });
    Route::get('/setup', [InstallController::class, 'show'])->name('setup');
    Route::post('/setup/access', [InstallController::class, 'unlock'])->middleware('throttle:5,1,setup-access:');
    Route::post('/setup/settings', [InstallController::class, 'save'])->middleware('throttle:30,1,setup-settings:');
    Route::post('/setup/owner', [InstallController::class, 'owner'])->middleware('throttle:5,1,setup-owner:');
});
