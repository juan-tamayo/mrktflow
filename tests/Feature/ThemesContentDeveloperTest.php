<?php

declare(strict_types=1);

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\ThemesContent\Actions\ValidateImport;
use App\Domain\ThemesContent\Contracts\ThemeRegistry;
use App\Domain\ThemesContent\Data\ProductView;
use App\Domain\ThemesContent\Data\ThemeRegistration;
use App\Domain\ThemesContent\Support\DeveloperThemeAssets;
use App\Domain\ThemesContent\Support\DeveloperThemeTrust;
use App\Domain\ThemesContent\Support\ThemeCoverage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->withoutVite();
});

afterEach(function (): void {
    foreach ($GLOBALS['t071ThemeDirs'] ?? [] as $path) {
        if (is_string($path) && is_dir($path)) {
            File::deleteDirectory($path);
        }
    }
    $GLOBALS['t071ThemeDirs'] = [];
    foreach ($GLOBALS['t071Zips'] ?? [] as $path) {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
    $GLOBALS['t071Zips'] = [];
});

function t071Product(): array
{
    return json_decode((string) file_get_contents(base_path('themes/default/preview/product.json')), true, flags: JSON_THROW_ON_ERROR);
}

/** @param  list<string>|null  $permissions */
function t071StaffAs(StaffAccount $staff, string $role, ?array $permissions = null): StaffAccount
{
    DB::table('staff_accounts')->where('id', $staff->id)->update([
        'role' => $role,
        'permissions' => json_encode($permissions ?? StaffPermissions::forRole($role)),
    ]);

    return $staff->fresh();
}

/**
 * @param  array<string, mixed>  $manifest
 * @param  list<string>  $files
 */
function t071ThemeDirectory(array $manifest, array $files = ['views/product.blade.php', 'src/product.ts', 'src/theme.css']): string
{
    $path = base_path('themes/t071-'.Str::uuid());
    File::ensureDirectoryExists($path);
    foreach ($files as $file) {
        File::ensureDirectoryExists(dirname($path.'/'.$file));
        File::put($path.'/'.$file, 'fixture');
    }
    File::put($path.'/theme.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $GLOBALS['t071ThemeDirs'][] = $path;

    return $path;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function t071Manifest(array $overrides = []): array
{
    return array_merge([
        'schemaVersion' => '1.0',
        'id' => 'acme/fixture',
        'version' => '1.0.0',
        'name' => 'Fixture',
        'requires' => ['core' => '>=1.0.0 <2.0.0', 'themeApi' => '1'],
        'templates' => ['product.default' => 'views/product.blade.php'],
        'sections' => ['hero'],
        'assets' => ['entry' => 'src/product.ts', 'style' => 'src/theme.css'],
    ], $overrides);
}

/**
 * @param  array<string, string>  $files
 */
function t071DeveloperZip(array $files): UploadedFile
{
    $path = sys_get_temp_dir().'/mrktflow-t071-'.Str::uuid().'.zip';
    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
    $GLOBALS['t071Zips'][] = $path;

    return new UploadedFile($path, 'theme.zip', 'application/zip', null, true);
}

it('ThemesContentDeveloper validates and installs the reviewed example theme without editing commerce core', function (): void {
    $owner = catalogInstalledOwner();
    $products = DB::table('products')->count();
    $orders = DB::table('orders')->count();

    $this->artisan('theme:validate', ['directory' => 'themes/example'])
        ->expectsOutputToContain('id: mrktflow/example')
        ->expectsOutputToContain('status: valid')
        ->expectsOutputToContain('trust: '.DeveloperThemeTrust::LEVEL)
        ->expectsOutputToContain('sandbox: '.DeveloperThemeTrust::SANDBOX)
        ->expectsOutputToContain('admin_upload: '.DeveloperThemeTrust::ADMIN_UPLOAD)
        ->expectsOutputToContain('activatable: no')
        ->assertSuccessful();

    $registration = app(ThemeRegistry::class)->find('mrktflow/example');
    expect($registration)->toBeInstanceOf(ThemeRegistration::class)
        ->and($registration->themeId)->toBe('mrktflow/example')
        ->and($registration->status)->toBe(ThemeRegistration::STATUS_VALID)
        ->and($registration->errors)->toBe([])
        ->and($registration->path)->toBe('example')
        ->and($registration->checksum)->toBe(hash_file('sha256', base_path('themes/example/theme.json')))
        ->and(app(ThemeRegistry::class)->isActivatable('mrktflow/example'))->toBeTrue()
        ->and(ThemeCoverage::missingSurfaces(array_keys(json_decode((string) file_get_contents(base_path('themes/example/theme.json')), true, flags: JSON_THROW_ON_ERROR)['templates'])))->not->toBe([])
        ->and(DB::table('theme_activations')->count())->toBe(0)
        ->and(DB::table('products')->count())->toBe($products)
        ->and(DB::table('orders')->count())->toBe($orders)
        ->and(DB::table('content_import_files')->count())->toBe(0);

    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/')->assertStatus(503);
});

it('ThemesContentDeveloper refuses executable admin uploads and paths outside the themes root', function (): void {
    $owner = catalogInstalledOwner();
    expect(fn () => app(ValidateImport::class)->validate($owner, [
        'file' => t071DeveloperZip([
            'manifest.json' => json_encode(['schemaVersion' => '1.0', 'kind' => 'template-package', 'documents' => []], JSON_THROW_ON_ERROR),
            'views/product.blade.php' => '@php echo 1; @endphp',
        ]),
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(ValidateImport::class)->validate($owner, [
        'file' => t071DeveloperZip([
            'manifest.json' => json_encode(['schemaVersion' => '1.0', 'kind' => 'template-package', 'documents' => []], JSON_THROW_ON_ERROR),
            'src/Custom.vue' => '<script setup>alert(1)</script>',
        ]),
    ]))->toThrow(ValidationException::class);
    expect(DB::table('content_import_files')->count())->toBe(0)
        ->and(DB::table('installed_themes')->where('theme_id', 'mrktflow/example')->count())->toBe(0);

    $this->artisan('theme:validate', ['directory' => sys_get_temp_dir()])
        ->expectsOutputToContain('Theme directory must be inside the themes root.')
        ->assertFailed();
    $this->get('/')->assertStatus(503);
});

it('ThemesContentDeveloper records an invalid fixture theme without activating it', function (): void {
    catalogInstalledOwner();
    $directory = t071ThemeDirectory(t071Manifest([
        'templates' => ['product.default' => 'views/missing.blade.php'],
    ]), ['src/product.ts', 'src/theme.css']);

    $this->artisan('theme:validate', ['directory' => 'themes/'.basename($directory)])
        ->expectsOutputToContain('id: acme/fixture')
        ->expectsOutputToContain('status: invalid')
        ->expectsOutputToContain('trust: '.DeveloperThemeTrust::LEVEL)
        ->expectsOutputToContain('activatable: no')
        ->assertFailed();

    $registration = app(ThemeRegistry::class)->find('acme/fixture');
    expect($registration?->status)->toBe(ThemeRegistration::STATUS_INVALID)
        ->and($registration?->activatable())->toBeFalse()
        ->and(implode(' ', $registration?->errors ?? []))->toContain('product.default')
        ->and(app(ThemeRegistry::class)->isActivatable('acme/fixture'))->toBeFalse()
        ->and(DB::table('theme_activations')->count())->toBe(0);
});

it('ThemesContentDeveloper renders the custom Blade product sheet from public ProductView data', function (): void {
    $product = ProductView::from(t071Product());
    $selected = $product->variants[0];
    $html = view('example-theme::product', [
        'product' => $product,
        'selected' => $selected,
        'preview' => true,
        'breadcrumbs' => [],
        'selectionState' => 'selected',
        'cartRevision' => 1,
        'cartItemCount' => 0,
        'assignedTemplateKey' => 'product.default',
        'metadata' => null,
    ])->render();

    expect($html)->toContain('data-theme="example"', 'Technical sheet', $product->title, $selected['money']['formatted'], 'name="variant"')
        ->and($html)->not->toContain('Add to cart', 'Buy now', 'supplier_cost');

    $unsafe = t071Product();
    $unsafe['title'] = '<img src=x onerror=alert(1)>';
    $unsafe['description'] = '<script>alert(1)</script>';
    $unsafeProduct = ProductView::from($unsafe);
    $escaped = view('example-theme::product', [
        'product' => $unsafeProduct,
        'selected' => $unsafeProduct->variants[0],
        'preview' => true,
        'breadcrumbs' => [],
        'selectionState' => 'selected',
        'cartRevision' => 1,
        'cartItemCount' => 0,
        'assignedTemplateKey' => 'product.default',
        'metadata' => null,
    ])->render();
    expect($escaped)->toContain('&lt;img src=x onerror=alert(1)&gt;', '&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($escaped)->not->toContain('<img src=x', '<script>alert(1)');
});

it('ThemesContentDeveloper lists confined theme asset entries without commerce-core paths', function (): void {
    $entries = DeveloperThemeAssets::viteEntries();
    expect($entries)->toContain('themes/example/src/product.ts', 'themes/example/src/theme.css', 'themes/default/src/product.ts')
        ->and($entries)->not->toContain('app/Domain/Catalog/Actions/SaveProduct.php')
        ->and($entries)->not->toContain('app/Domain/CheckoutOrders')
        ->and($entries)->not->toContain('app/Domain/Pricing');
    foreach ($entries as $entry) {
        expect($entry)->not->toContain('..')
            ->and(str_starts_with($entry, 'themes/'))->toBeTrue()
            ->and(is_file(base_path($entry)))->toBeTrue();
    }
});

it('ThemesContentDeveloper keeps GET / closed and privileged staff behind two-factor', function (): void {
    $owner = catalogInstalledOwner();
    $this->artisan('theme:validate', ['directory' => 'themes/example'])->assertSuccessful();

    $this->get('/')->assertStatus(503);
    $this->get('/admin/themes')->assertRedirect('/admin/login');

    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper',
        'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->getJson('/admin/themes')->assertUnauthorized();

    $fulfillment = t071StaffAs($owner, 'fulfillment');
    $this->actingAs($fulfillment, 'staff')->withSession(catalogStaffSession($fulfillment));
    $this->getJson('/admin/themes')->assertForbidden();

    $owner = t071StaffAs($owner, 'owner');
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version]);
    $this->get('/admin/themes')->assertRedirect('/admin/two-factor');
    $this->post('/admin/themes/activate', ['theme_id' => 'mrktflow/example'])->assertRedirect('/admin/two-factor');
    expect(DB::table('theme_activations')->count())->toBe(0);
    $this->get('/')->assertStatus(503);
});
