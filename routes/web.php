<?php

use App\Http\Controllers\Admin\InstallController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StaffInvitationController;
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
        Route::get('/admin/staff', [StaffController::class, 'index']);
        Route::post('/admin/staff/invitations', [StaffInvitationController::class, 'create'])->middleware('throttle:10,1,staff-invite:');
        Route::patch('/admin/staff/{staffId}', [StaffController::class, 'update'])->whereUuid('staffId')->middleware('throttle:30,1,staff-access:');
    });
    Route::get('/staff/invitation', [StaffInvitationController::class, 'show']);
    Route::post('/staff/invitation', [StaffInvitationController::class, 'accept'])->middleware('throttle:5,1,staff-invite-accept:');
    Route::get('/setup', [InstallController::class, 'show'])->name('setup');
    Route::post('/setup/access', [InstallController::class, 'unlock'])->middleware('throttle:5,1,setup-access:');
    Route::post('/setup/settings', [InstallController::class, 'save'])->middleware('throttle:30,1,setup-settings:');
    Route::post('/setup/owner', [InstallController::class, 'owner'])->middleware('throttle:5,1,setup-owner:');
});
