<?php

use App\Http\Controllers\Admin\InstallController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StaffInvitationController;
use App\Http\Controllers\Admin\StaffSessionController;
use App\Http\Controllers\Storefront\CustomerAccountController;
use App\Http\Controllers\Storefront\CustomerEmailVerificationController;
use App\Http\Controllers\Storefront\CustomerPasswordRecoveryController;
use App\Http\Middleware\RequireCustomerGuest;
use App\Http\Middleware\RequireCustomerSession;
use App\Http\Middleware\RequireInstalledStore;
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

Route::prefix('account')->middleware(RequireInstalledStore::class)->group(function (): void {
    Route::get('/verify', [CustomerEmailVerificationController::class, 'show']);
    Route::get('/forgot-password', [CustomerPasswordRecoveryController::class, 'requestForm']);
    Route::post('/forgot-password', [CustomerPasswordRecoveryController::class, 'request'])->middleware('throttle:customer-recovery');
    Route::get('/reset-password', [CustomerPasswordRecoveryController::class, 'resetForm']);
    Route::post('/reset-password', [CustomerPasswordRecoveryController::class, 'reset'])->middleware('throttle:5,1,customer-reset:');
    Route::middleware(RequireCustomerGuest::class)->group(function (): void {
        Route::get('/register', [CustomerAccountController::class, 'registerForm']);
        Route::post('/register', [CustomerAccountController::class, 'register'])->middleware('throttle:5,1,customer-register:');
        Route::get('/login', [CustomerAccountController::class, 'loginForm'])->name('customer.login');
        Route::post('/login', [CustomerAccountController::class, 'login'])->middleware('throttle:customer-login');
    });
    Route::middleware(RequireCustomerSession::class)->group(function (): void {
        Route::get('/', [CustomerAccountController::class, 'home']);
        Route::post('/logout', [CustomerAccountController::class, 'logout']);
        Route::post('/verification', [CustomerEmailVerificationController::class, 'send'])->middleware('throttle:customer-verification');
        Route::post('/verify', [CustomerEmailVerificationController::class, 'verify'])->middleware('throttle:5,1,customer-verify:');
    });
});
