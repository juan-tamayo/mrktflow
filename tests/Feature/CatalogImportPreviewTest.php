<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\PreviewProductImport;
use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveProductSchema;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Jobs\ValidateProductImport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

it('CatalogImportPreview maps columns, reports mixed-validity rows, and writes no products', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $existing = app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    $csv = catalogImportCsv(
        ['Product Title', 'SKU', 'Slug', 'Description', 'Status', 'Price', 'Image', 'Alt', 'Type'],
        [
            ['Oak Desk', 'DESK-01', 'oak-desk', 'Solid oak writing desk.', 'draft', '12900', $image, 'Oak desk from the front', 'physical'],
            ['Studio Lamp Updated', 'LAMP-001', 'studio-lamp', 'A simple physical lamp for the workbench.', 'published', '2599', $image, 'Brushed steel studio lamp', 'physical'],
            ['Bad Price', 'BAD-PRICE', 'bad-price', 'Broken price.', 'draft', '-1', $image, 'Broken price image', 'physical'],
            ['=HYPERLINK("http://evil.test")', 'FORMULA-1', 'formula-lamp', 'Formula title.', 'draft', '100', $image, 'Formula title image', 'physical'],
            ['Duplicate Desk', 'DESK-01', 'oak-desk-dup', 'Second desk row.', 'draft', '13000', $image, 'Duplicate desk image', 'physical'],
        ],
    );
    $beforeProducts = DB::table('products')->count();
    $beforeVariants = DB::table('product_variants')->count();
    $preview = app(PreviewProductImport::class)->preview($owner, [
        'file' => UploadedFile::fake()->createWithContent('catalog.csv', $csv),
        'mapping' => [
            'Product Title' => 'title',
            'SKU' => 'sku',
            'Slug' => 'slug',
            'Description' => 'description',
            'Status' => 'status',
            'Price' => 'price_amount',
            'Image' => 'image_asset_id',
            'Alt' => 'image_alt',
            'Type' => 'type',
        ],
    ]);
    expect($preview['sample_columns'])->toContain('sku', 'title', 'slug', 'price_amount');
    expect($preview['mapping']['Product Title'])->toBe('title');
    expect($preview['create_count'])->toBe(1);
    expect($preview['update_count'])->toBe(1);
    expect($preview['error_count'])->toBe(3);
    expect($preview['rows'][0]['intent'])->toBe('create');
    expect($preview['rows'][0]['valid'])->toBeTrue();
    expect($preview['rows'][0]['sku'])->toBe('DESK-01');
    expect($preview['rows'][1]['intent'])->toBe('update');
    expect($preview['rows'][1]['valid'])->toBeTrue();
    expect($preview['rows'][1]['product_id'])->toBe($existing['id']);
    expect($preview['rows'][2]['valid'])->toBeFalse();
    expect(implode(' ', $preview['rows'][2]['errors']))->toContain('price');
    expect($preview['rows'][3]['valid'])->toBeFalse();
    expect(implode(' ', $preview['rows'][3]['errors']))->toContain('spreadsheet formula');
    expect($preview['rows'][4]['valid'])->toBeFalse();
    expect(implode(' ', $preview['rows'][4]['errors']))->toContain('duplicated');
    expect(DB::table('products')->count())->toBe($beforeProducts);
    expect(DB::table('product_variants')->count())->toBe($beforeVariants);
    expect(DB::table('products')->where('id', $existing['id'])->value('title'))->toBe('Studio Lamp');
    expect(DB::table('product_variants')->where('sku', 'LAMP-001')->value('price_amount'))->toBe(2499);
    $this->get('/')->assertOk()->assertDontSee('Oak Desk');
});

it('CatalogImportPreview exports a formula-safe mixed-validity error report', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $csv = catalogImportCsv(
        ['sku', 'title', 'slug', 'description', 'status', 'price_amount', 'image_asset_id', 'image_alt'],
        [
            ['OK-01', 'Valid Lamp', 'valid-lamp', 'A valid row.', 'draft', '1200', $image, 'Valid lamp image'],
            ['=CMD|\' /C calc\'!A0', 'Evil', 'evil-lamp', 'Bad sku.', 'draft', '1200', $image, 'Evil image'],
        ],
    );
    $preview = app(PreviewProductImport::class)->preview($owner, [
        'file' => UploadedFile::fake()->createWithContent('catalog.csv', $csv),
    ]);
    $export = app(PreviewProductImport::class)->errorCsv($preview);
    expect($export)->toContain('"line","sku","intent","errors"');
    expect($export)->not->toMatch('/(?:^|,)=CMD/m');
    expect($export)->toContain("'=CMD");
    expect($export)->toContain('spreadsheet formula');
    expect($export)->not->toContain('Valid Lamp');
});

it('CatalogImportPreview validates schema attributes and category paths without writing', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $csv = catalogImportCsv(
        ['sku', 'title', 'slug', 'description', 'status', 'price_amount', 'image_asset_id', 'image_alt', 'category_paths', 'schema_key', 'sensor.megapixels', 'lens.mount'],
        [
            ['CAM-01', 'RF Body', 'rf-body', 'A camera body.', 'draft', '99900', $image, 'Camera body', 'lighting', 'camera', '24.2', 'rf'],
            ['CAM-02', 'Bad Mount', 'bad-mount', 'Invalid enum.', 'draft', '89900', $image, 'Bad camera', 'missing-path', 'camera', '24.2', 'ef'],
        ],
    );
    $preview = app(PreviewProductImport::class)->preview($owner, [
        'file' => UploadedFile::fake()->createWithContent('cameras.csv', $csv),
    ]);
    expect($preview['rows'][0]['valid'])->toBeTrue();
    expect($preview['rows'][1]['valid'])->toBeFalse();
    $errors = implode(' ', $preview['rows'][1]['errors']);
    expect($errors)->toContain('lens.mount')->toContain('category');
    expect(DB::table('products')->count())->toBe(0);
    expect(DB::table('product_schemas')->where('id', $schema['id'])->count())->toBe(1);
});

it('CatalogImportPreview HTTP keeps privileged staff behind two-factor and denies outsiders', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/products/import')->assertRedirect('/admin/two-factor');
    $this->post('/admin/products/import', ['file' => UploadedFile::fake()->createWithContent('x.csv', "sku\nA\n")])->assertRedirect('/admin/two-factor');
    $this->get('/admin/products/import')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/products/import', ['file' => UploadedFile::fake()->createWithContent('x.csv', "sku\nA\n")])->assertForbidden();
    Auth::guard('staff')->logout();
    $this->flushSession();
    $this->postJson('/admin/products/import', ['file' => UploadedFile::fake()->createWithContent('x.csv', "sku\nA\n")])->assertUnauthorized();
    $customer = new CustomerAccount;
    $customer->forceFill(['name' => 'Shopper', 'email' => 'shopper@example.test', 'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1])->save();
    $this->actingAs($customer, 'customer')->postJson('/admin/products/import', ['file' => UploadedFile::fake()->createWithContent('x.csv', "sku\nA\n")])->assertUnauthorized();
    foreach (['content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()))
            ->getJson('/admin/products/import')->assertForbidden();
        $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()))
            ->postJson('/admin/products/import', ['file' => UploadedFile::fake()->createWithContent('x.csv', "sku\nA\n")])->assertForbidden();
    }
});

it('CatalogImportPreview HTTP lets authorized staff dry-run a CSV and download row errors', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $this->get('/admin/products/import')->assertRedirect('/admin/login');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/products/import')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ProductImport')
        ->where('sampleColumns.0', 'sku')
        ->where('preview', null));
    $csv = catalogImportCsv(
        ['sku', 'title', 'slug', 'description', 'status', 'price_amount', 'image_asset_id', 'image_alt'],
        [
            ['DESK-01', 'Oak Desk', 'oak-desk', 'Solid oak writing desk.', 'draft', '12900', $image, 'Oak desk from the front'],
            ['BAD', 'No Price', 'no-price', 'Missing money.', 'draft', 'abc', $image, 'Broken'],
        ],
    );
    $this->from('/admin/products/import')->post('/admin/products/import', [
        'file' => UploadedFile::fake()->createWithContent('catalog.csv', $csv),
    ])->assertRedirect();
    $importId = (string) DB::table('product_import_files')->value('id');
    expect($importId)->not->toBe('');
    $this->get('/admin/products/import/'.$importId)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ProductImport')
        ->where('preview.create_count', 1)
        ->where('preview.error_count', 1)
        ->where('preview.rows.0.sku', 'DESK-01')
        ->where('preview.rows.1.valid', false));
    $errors = $this->get('/admin/products/import/'.$importId.'/errors.csv');
    $errors->assertOk();
    expect($errors->headers->get('content-type'))->toStartWith('text/csv');
    expect($errors->headers->get('content-disposition'))->toContain('attachment');
    expect($errors->streamedContent())->toContain('BAD')->toContain('price');
    expect(DB::table('products')->count())->toBe(0);
    $this->get('/')->assertOk()->assertDontSee('Oak Desk');
    $this->get('/admin/products')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ProductIndex')
        ->where('canWrite', true));
});

it('CatalogImportPreview queued validation writes a private report without catalog mutations', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $csv = catalogImportCsv(
        ['sku', 'title', 'slug', 'description', 'status', 'price_amount', 'image_asset_id', 'image_alt'],
        [['DESK-01', 'Oak Desk', 'oak-desk', 'Solid oak writing desk.', 'draft', '12900', $image, 'Oak desk from the front']],
    );
    $preview = app(PreviewProductImport::class)->preview($owner, [
        'file' => UploadedFile::fake()->createWithContent('catalog.csv', $csv),
    ]);
    $before = DB::table('products')->count();
    $job = new ValidateProductImport($preview['import_id'], $owner->id);
    $job->handle(app(PreviewProductImport::class));
    $report = Storage::disk('local')->get('catalog-imports/'.$preview['import_id'].'/report.json');
    expect($report)->toContain('DESK-01');
    expect(json_decode((string) $report, true, flags: JSON_THROW_ON_ERROR)['create_count'])->toBe(1);
    expect(DB::table('products')->count())->toBe($before);
    expect(json_encode($preview))->not->toContain('catalog-imports/');
});

it('CatalogImportPreview rejects unauthorized actors unsafe files and unknown mappings', function (): void {
    $owner = catalogInstalledOwner();
    expect(fn () => app(PreviewProductImport::class)->preview($owner, [
        'file' => UploadedFile::fake()->createWithContent('catalog.php', "<?php echo 1;\n"),
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(PreviewProductImport::class)->preview($owner, [
        'file' => UploadedFile::fake()->createWithContent('catalog.csv', str_repeat('a', 1_048_577)),
    ]))->toThrow(ValidationException::class);
    $csv = catalogImportCsv(['sku'], [['DESK-01']]);
    expect(fn () => app(PreviewProductImport::class)->preview($owner, [
        'file' => UploadedFile::fake()->createWithContent('catalog.csv', $csv),
        'mapping' => ['sku' => 'password'],
    ]))->toThrow(ValidationException::class);
    DB::table('staff_accounts')->where('id', $owner->id)->update([
        'role' => 'content_editor',
        'permissions' => json_encode(StaffPermissions::forRole('content_editor')),
    ]);
    expect(fn () => app(PreviewProductImport::class)->preview($owner->fresh(), [
        'file' => UploadedFile::fake()->createWithContent('catalog.csv', $csv),
    ]))->toThrow(AuthorizationException::class);
});
