<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\ApplyProductImport;
use App\Domain\Catalog\Actions\ArchiveProduct;
use App\Domain\Catalog\Actions\ExportProducts;
use App\Domain\Catalog\Actions\PreviewProductImport;
use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveProductSchema;
use App\Domain\Catalog\Actions\SaveVariants;
use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\Catalog\Exceptions\CatalogProductUnpublished;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\StaffPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(DatabaseMigrations::class);
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

it('CatalogImportApply writes validated create and update rows and records per-row results', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $existing = app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    $csv = catalogImportCsv(
        ['sku', 'title', 'slug', 'description', 'status', 'price_amount', 'image_asset_id', 'image_alt', 'type'],
        [
            ['DESK-01', 'Oak Desk', 'oak-desk', 'Solid oak writing desk.', 'draft', '12900', $image, 'Oak desk from the front', 'physical'],
            ['LAMP-001', 'Studio Lamp Updated', 'studio-lamp', 'A simple physical lamp for the workbench.', 'published', '2599', $image, 'Brushed steel studio lamp', 'physical'],
            ['BAD-PRICE', 'Bad Price', 'bad-price', 'Broken price.', 'draft', '-1', $image, 'Broken price image', 'physical'],
        ],
    );
    $preview = app(PreviewProductImport::class)->preview($owner, [
        'file' => UploadedFile::fake()->createWithContent('catalog.csv', $csv),
    ]);
    $applied = app(ApplyProductImport::class)->apply($owner, ['import_id' => $preview['import_id']]);
    expect($applied['applied'])->toBeTrue();
    expect($applied['create_count'])->toBe(1);
    expect($applied['update_count'])->toBe(1);
    expect($applied['error_count'])->toBe(1);
    expect($applied['rows'][0]['result'])->toBe('created');
    expect($applied['rows'][0]['product_id'])->not->toBeNull();
    expect($applied['rows'][1]['result'])->toBe('updated');
    expect($applied['rows'][1]['product_id'])->toBe($existing['id']);
    expect($applied['rows'][2]['result'])->toBe('skipped');
    expect(DB::table('products')->count())->toBe(2);
    expect(DB::table('product_variants')->where('sku', 'DESK-01')->count())->toBe(1);
    expect(DB::table('products')->where('slug', 'oak-desk')->value('title'))->toBe('Oak Desk');
    expect(DB::table('products')->where('id', $existing['id'])->value('title'))->toBe('Studio Lamp Updated');
    expect(DB::table('product_variants')->where('sku', 'LAMP-001')->value('price_amount'))->toBe(2599);
    expect(DB::table('products')->where('slug', 'bad-price')->exists())->toBeFalse();
    $this->get('/')->assertStatus(503)->assertDontSee('Oak Desk');
    $this->get('/products/oak-desk')->assertNotFound();
});

it('CatalogImportApply replays the same import intent without duplicating variants', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $csv = catalogImportCsv(
        ['sku', 'title', 'slug', 'description', 'status', 'price_amount', 'image_asset_id', 'image_alt'],
        [['DESK-01', 'Oak Desk', 'oak-desk', 'Solid oak writing desk.', 'draft', '12900', $image, 'Oak desk from the front']],
    );
    $preview = app(PreviewProductImport::class)->preview($owner, [
        'file' => UploadedFile::fake()->createWithContent('catalog.csv', $csv),
    ]);
    $first = app(ApplyProductImport::class)->apply($owner, ['import_id' => $preview['import_id']]);
    $productId = $first['rows'][0]['product_id'];
    expect(DB::table('outbox_events')->where('intent_key', 'catalog:import:apply:'.$preview['import_id'])->count())->toBe(1);
    $replay = app(ApplyProductImport::class)->apply($owner, ['import_id' => $preview['import_id']]);
    expect($replay['replayed'])->toBeTrue();
    expect($replay['rows'][0]['product_id'])->toBe($productId);
    expect(DB::table('products')->count())->toBe(1);
    expect(DB::table('product_variants')->count())->toBe(1);
    expect(DB::table('outbox_events')->where('intent_key', 'catalog:import:apply:'.$preview['import_id'])->count())->toBe(1);
    $again = app(PreviewProductImport::class)->preview($owner, [
        'file' => UploadedFile::fake()->createWithContent('catalog.csv', $csv),
    ]);
    $secondFile = app(ApplyProductImport::class)->apply($owner, ['import_id' => $again['import_id']]);
    expect($secondFile['create_count'])->toBe(0);
    expect($secondFile['update_count'])->toBe(1);
    expect($secondFile['rows'][0]['result'])->toBe('updated');
    expect($secondFile['rows'][0]['product_id'])->toBe($productId);
    expect(DB::table('products')->count())->toBe(1);
    expect(DB::table('product_variants')->where('sku', 'DESK-01')->count())->toBe(1);
});

it('CatalogImportApply archives without dropping SKUs and keeps public catalog private', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $saved = app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    $matrix = catalogVariantMatrix($saved['id'], $saved['version'], $image);
    app(SaveVariants::class)->save($owner, $matrix);
    $skuCount = DB::table('product_variants')->where('product_id', $saved['id'])->count();
    $keptSku = (string) DB::table('product_variants')->where('product_id', $saved['id'])->where('sku', 'LAMP-PINE-S')->value('id');
    expect($skuCount)->toBeGreaterThan(1);
    expect($keptSku)->not->toBe('');
    $archived = app(ArchiveProduct::class)->archive($owner, ['id' => $saved['id'], 'version' => $saved['version'] + 1]);
    expect($archived['status'])->toBe('archived');
    expect(DB::table('products')->where('id', $saved['id'])->value('status'))->toBe('archived');
    expect(DB::table('product_variants')->where('product_id', $saved['id'])->count())->toBe($skuCount);
    expect(DB::table('product_variants')->where('id', $keptSku)->exists())->toBeTrue();
    expect(fn () => app(CatalogReader::class)->publicProduct('studio-lamp'))->toThrow(CatalogProductUnpublished::class);
    $this->get('/products/studio-lamp')->assertNotFound()->assertDontSee('Studio Lamp');
    $this->get('/search?q=Studio')->assertOk()->assertDontSee('Studio Lamp');
    $this->get('/')->assertStatus(503);
    $replay = app(ArchiveProduct::class)->archive($owner, ['id' => $saved['id'], 'version' => $archived['version']]);
    expect($replay['status'])->toBe('archived');
    expect($replay['version'])->toBe($archived['version']);
    expect(DB::table('product_variants')->where('sku', 'LAMP-PINE-S')->exists())->toBeTrue();
});

it('CatalogImportApply exports a formula-safe catalog including archived SKUs', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    $saved = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'title' => '=HYPERLINK("http://evil.test")',
        'status' => 'published',
        'category_ids' => [(string) DB::table('categories')->value('id')],
        'schema_id' => $schema['id'],
        'attributes' => ['sensor.megapixels' => '24.2', 'lens.mount' => 'rf'],
    ]));
    app(ArchiveProduct::class)->archive($owner, ['id' => $saved['id'], 'version' => $saved['version']]);
    $csv = app(ExportProducts::class)->export($owner);
    expect($csv)->toContain('"sku","title","slug","description","status","type","price_amount"');
    expect($csv)->toContain('LAMP-001');
    expect($csv)->toContain('archived');
    expect($csv)->not->toMatch('/(?:^|,)=HYPERLINK/m');
    expect($csv)->toContain("'=HYPERLINK");
    expect($csv)->toContain('lighting');
    expect($csv)->toContain('camera');
});

it('CatalogImportApply HTTP keeps privileged staff behind two-factor and denies outsiders', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->post('/admin/products/import/11111111-1111-4111-8111-111111111111/apply')->assertRedirect('/admin/two-factor');
    $this->get('/admin/products/export.csv')->assertRedirect('/admin/two-factor');
    $this->post('/admin/products/11111111-1111-4111-8111-111111111111/archive')->assertRedirect('/admin/two-factor');
    $this->getJson('/admin/products/export.csv')->assertForbidden();
    Auth::guard('staff')->logout();
    $this->flushSession();
    $this->postJson('/admin/products/import/11111111-1111-4111-8111-111111111111/apply')->assertUnauthorized();
    $this->getJson('/admin/products/export.csv')->assertUnauthorized();
    $customer = new CustomerAccount;
    $customer->forceFill(['name' => 'Shopper', 'email' => 'shopper@example.test', 'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1])->save();
    $this->actingAs($customer, 'customer')->postJson('/admin/products/import/11111111-1111-4111-8111-111111111111/apply')->assertUnauthorized();
    $this->actingAs($customer, 'customer')->getJson('/admin/products/export.csv')->assertUnauthorized();
    foreach (['content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $staff = $owner->fresh();
        $this->actingAs($staff, 'staff')->withSession(catalogStaffSession($staff))
            ->postJson('/admin/products/import/11111111-1111-4111-8111-111111111111/apply')->assertForbidden();
        $this->actingAs($staff, 'staff')->withSession(catalogStaffSession($staff))
            ->getJson('/admin/products/export.csv')->assertForbidden();
    }
});

it('CatalogImportApply HTTP lets authorized staff apply, export, and archive', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $csv = catalogImportCsv(
        ['sku', 'title', 'slug', 'description', 'status', 'price_amount', 'image_asset_id', 'image_alt'],
        [
            ['DESK-01', 'Oak Desk', 'oak-desk', 'Solid oak writing desk.', 'published', '12900', $image, 'Oak desk from the front'],
            ['BAD', 'No Price', 'no-price', 'Missing money.', 'draft', 'abc', $image, 'Broken'],
        ],
    );
    $this->from('/admin/products/import')->post('/admin/products/import', [
        'file' => UploadedFile::fake()->createWithContent('catalog.csv', $csv),
    ])->assertRedirect();
    $importId = (string) DB::table('product_import_files')->value('id');
    $this->from('/admin/products/import/'.$importId)->post('/admin/products/import/'.$importId.'/apply')
        ->assertRedirect('/admin/products/import/'.$importId);
    $this->get('/admin/products/import/'.$importId)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ProductImport')
        ->where('preview.create_count', 0)
        ->where('applied.create_count', 1)
        ->where('applied.rows.0.result', 'created')
        ->where('applied.rows.1.result', 'skipped'));
    expect(DB::table('products')->where('slug', 'oak-desk')->value('status'))->toBe('published');
    $this->get('/products/oak-desk')->assertOk()->assertSee('Oak Desk');
    $export = $this->get('/admin/products/export.csv');
    $export->assertOk();
    expect($export->headers->get('content-type'))->toStartWith('text/csv');
    expect($export->headers->get('content-disposition'))->toContain('attachment');
    expect($export->streamedContent())->toContain('DESK-01')->toContain('Oak Desk');
    $productId = (string) DB::table('products')->where('slug', 'oak-desk')->value('id');
    $version = (int) DB::table('products')->where('id', $productId)->value('version');
    $this->from('/admin/products/'.$productId)->post('/admin/products/'.$productId.'/archive', ['version' => $version])
        ->assertRedirect('/admin/products/'.$productId);
    expect(DB::table('products')->where('id', $productId)->value('status'))->toBe('archived');
    $this->get('/products/oak-desk')->assertNotFound()->assertDontSee('Oak Desk');
    $this->get('/')->assertStatus(503)->assertDontSee('Oak Desk');
    $this->get('/admin/products')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ProductIndex')
        ->where('products.data.0.status', 'archived'));
});

it('CatalogImportApply rejects unauthorized actors and unknown imports', function (): void {
    $owner = catalogInstalledOwner();
    expect(fn () => app(ApplyProductImport::class)->apply($owner, ['import_id' => '11111111-1111-4111-8111-111111111111']))
        ->toThrow(HttpException::class);
    expect(fn () => app(ArchiveProduct::class)->archive($owner, ['id' => '11111111-1111-4111-8111-111111111111', 'version' => 1]))
        ->toThrow(HttpException::class);
    DB::table('staff_accounts')->where('id', $owner->id)->update([
        'role' => 'content_editor',
        'permissions' => json_encode(StaffPermissions::forRole('content_editor')),
    ]);
    $denied = $owner->fresh();
    expect(fn () => app(ApplyProductImport::class)->apply($denied, ['import_id' => '11111111-1111-4111-8111-111111111111']))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ExportProducts::class)->export($denied))->toThrow(AuthorizationException::class);
    expect(fn () => app(ArchiveProduct::class)->archive($denied, ['id' => '11111111-1111-4111-8111-111111111111', 'version' => 1]))
        ->toThrow(AuthorizationException::class);
});
