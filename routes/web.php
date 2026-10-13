<?php

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DigitalAssetController;
use App\Http\Controllers\Admin\InstallController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductInventoryController;
use App\Http\Controllers\Admin\ProductSchemaController;
use App\Http\Controllers\Admin\ProductVariantController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StaffInvitationController;
use App\Http\Controllers\Admin\StaffSessionController;
use App\Http\Controllers\Admin\ThemePreviewController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\CustomerAccountController;
use App\Http\Controllers\Storefront\CustomerAddressController;
use App\Http\Controllers\Storefront\CustomerEmailVerificationController;
use App\Http\Controllers\Storefront\CustomerPasswordRecoveryController;
use App\Http\Controllers\Storefront\CustomerProfileController;
use App\Http\Controllers\Storefront\SearchController;
use App\Http\Middleware\RequireCustomerGuest;
use App\Http\Middleware\RequireCustomerSession;
use App\Http\Middleware\RequireInstalledStore;
use App\Http\Middleware\RequireStaffSession;
use Illuminate\Support\Facades\Route;
use Inertia\Middleware;

Route::get('/', fn () => response()->view('storefront.closed', [], 503)->header('Retry-After', '3600'));
Route::get('/search', [SearchController::class, 'show']);
Route::get('/products/{slug}', [CatalogController::class, 'product'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
Route::get('/categories/{path}', [CatalogController::class, 'category'])->where('path', '[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*');
Route::get('/admin/theme-preview', [ThemePreviewController::class, 'show'])->middleware(RequireStaffSession::class);

Route::middleware(Middleware::class)->group(function (): void {
    Route::get('/admin/login', [StaffSessionController::class, 'show'])->name('staff.login');
    Route::post('/admin/login', [StaffSessionController::class, 'login'])->middleware('throttle:staff-login');
    Route::middleware(RequireStaffSession::class)->group(function (): void {
        Route::get('/admin', [StaffSessionController::class, 'home']);
        Route::post('/admin/logout', [StaffSessionController::class, 'logout']);
        Route::get('/admin/two-factor', [TwoFactorController::class, 'show']);
        Route::post('/admin/two-factor', [TwoFactorController::class, 'confirm'])->middleware('throttle:5,1,staff-two-factor:');
        Route::post('/admin/two-factor/challenge', [TwoFactorController::class, 'challenge'])->middleware('throttle:5,1,staff-two-factor:');
        Route::post('/admin/two-factor/reauthenticate', [TwoFactorController::class, 'reauthenticate'])->middleware('throttle:5,1,staff-two-factor:');
        Route::get('/admin/staff', [StaffController::class, 'index']);
        Route::post('/admin/staff/invitations', [StaffInvitationController::class, 'create'])->middleware('throttle:10,1,staff-invite:');
        Route::patch('/admin/staff/{staffId}', [StaffController::class, 'update'])->whereUuid('staffId')->middleware('throttle:30,1,staff-access:');
        Route::get('/admin/products', [ProductController::class, 'index']);
        Route::get('/admin/products/create', [ProductController::class, 'create']);
        Route::post('/admin/products', [ProductController::class, 'store'])->middleware('throttle:30,1,catalog-product:');
        Route::get('/admin/products/{productId}', [ProductController::class, 'edit'])->whereUuid('productId');
        Route::patch('/admin/products/{productId}', [ProductController::class, 'update'])->whereUuid('productId')->middleware('throttle:30,1,catalog-product:');
        Route::put('/admin/products/{productId}/variants', [ProductVariantController::class, 'update'])->whereUuid('productId')->middleware('throttle:30,1,catalog-variants:');
        Route::patch('/admin/products/{productId}/inventory', [ProductInventoryController::class, 'update'])->whereUuid('productId')->middleware('throttle:30,1,catalog-inventory:');
        Route::post('/admin/products/{productId}/digital-assets', [DigitalAssetController::class, 'store'])->whereUuid('productId')->middleware('throttle:20,1,catalog-digital:');
        Route::put('/admin/products/{productId}/variants/{variantId}/digital-assets', [DigitalAssetController::class, 'attach'])->whereUuid('productId')->whereUuid('variantId')->middleware('throttle:20,1,catalog-digital:');
        Route::get('/admin/schemas', [ProductSchemaController::class, 'index']);
        Route::get('/admin/schemas/create', [ProductSchemaController::class, 'create']);
        Route::post('/admin/schemas', [ProductSchemaController::class, 'store'])->middleware('throttle:30,1,catalog-schema:');
        Route::get('/admin/schemas/{schemaId}', [ProductSchemaController::class, 'edit'])->whereUuid('schemaId');
        Route::patch('/admin/schemas/{schemaId}', [ProductSchemaController::class, 'update'])->whereUuid('schemaId')->middleware('throttle:30,1,catalog-schema:');
        Route::post('/admin/schemas/{schemaId}/migration/preview', [ProductSchemaController::class, 'previewMigration'])->whereUuid('schemaId')->middleware('throttle:30,1,catalog-schema-migration:');
        Route::post('/admin/schemas/{schemaId}/migration', [ProductSchemaController::class, 'applyMigration'])->whereUuid('schemaId')->middleware('throttle:30,1,catalog-schema-migration:');
        Route::get('/admin/categories', [CategoryController::class, 'index']);
        Route::get('/admin/categories/create', [CategoryController::class, 'create']);
        Route::post('/admin/categories', [CategoryController::class, 'store'])->middleware('throttle:30,1,catalog-category:');
        Route::get('/admin/categories/{categoryId}', [CategoryController::class, 'edit'])->whereUuid('categoryId');
        Route::patch('/admin/categories/{categoryId}', [CategoryController::class, 'update'])->whereUuid('categoryId')->middleware('throttle:30,1,catalog-category:');
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
        Route::get('/profile', [CustomerProfileController::class, 'show']);
        Route::post('/profile', [CustomerProfileController::class, 'update'])->middleware('throttle:5,1,customer-profile:');
        Route::get('/addresses', [CustomerAddressController::class, 'index']);
        Route::post('/addresses', [CustomerAddressController::class, 'save'])->middleware('throttle:20,1,customer-address:');
        Route::get('/addresses/{addressId}', [CustomerAddressController::class, 'edit'])->whereUuid('addressId');
        Route::post('/addresses/{addressId}', [CustomerAddressController::class, 'save'])->whereUuid('addressId')->middleware('throttle:20,1,customer-address:');
        Route::post('/addresses/{addressId}/delete', [CustomerAddressController::class, 'remove'])->whereUuid('addressId')->middleware('throttle:20,1,customer-address:');
        Route::post('/logout', [CustomerAccountController::class, 'logout']);
        Route::post('/verification', [CustomerEmailVerificationController::class, 'send'])->middleware('throttle:customer-verification');
        Route::post('/verify', [CustomerEmailVerificationController::class, 'verify'])->middleware('throttle:5,1,customer-verify:');
    });
});
