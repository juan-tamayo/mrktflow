<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveVariants;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\StaffPermissions;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoStoreSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

it('CatalogDigitalAsset versions private files, pins them to digital variants, and omits storage keys from public views', function (): void {
    $owner = catalogInstalledOwner();
    $saved = app(SaveProduct::class)->save($owner, catalogDigitalProductInput(catalogReadyImage($owner->id), ['status' => 'published']));
    expect($saved['type'])->toBe('digital');
    $variantId = (string) DB::table('product_variants')->where('product_id', $saved['id'])->value('id');
    expect((int) DB::table('product_variants')->where('id', $variantId)->value('max_quantity'))->toBe(1)
        ->and((bool) DB::table('product_variants')->where('id', $variantId)->value('tracks_inventory'))->toBeFalse();
    $first = app(VersionDigitalAsset::class)->version($owner, [
        'product_id' => $saved['id'],
        'label' => 'Lighting guide PDF',
        'file' => UploadedFile::fake()->createWithContent('guide.pdf', "%PDF-1.7\nfictional fixture\n%%EOF\n"),
    ]);
    expect($first['status'])->toBe('ready')
        ->and($first['version'])->toBe(1)
        ->and($first)->not->toHaveKeys(['original_key', 'original_name', 'media_asset_id']);
    $replaced = app(VersionDigitalAsset::class)->version($owner, [
        'product_id' => $saved['id'],
        'digital_asset_id' => $first['asset_id'],
        'label' => 'Lighting guide PDF',
        'file' => catalogPrivateTextFile('guide-v2.txt', "Revised fictional lighting guide.\n"),
    ]);
    expect($replaced['asset_id'])->toBe($first['asset_id'])
        ->and($replaced['version'])->toBe(2)
        ->and($replaced['id'])->not->toBe($first['id']);
    expect(DB::table('digital_asset_versions')->where('digital_asset_id', $first['asset_id'])->count())->toBe(2);
    $companion = app(VersionDigitalAsset::class)->version($owner, [
        'product_id' => $saved['id'],
        'label' => 'Printable checklist',
        'file' => catalogPrivateTextFile('checklist.txt'),
    ]);
    app(VersionDigitalAsset::class)->attach($owner, [
        'product_id' => $saved['id'],
        'variant_id' => $variantId,
        'version_ids' => [$first['id'], $companion['id']],
    ]);
    $staff = app(CatalogReader::class)->staffProduct($saved['id']);
    expect($staff['type'])->toBe('digital')
        ->and($staff['variants'][0]['max_quantity'])->toBe(1)
        ->and($staff['variants'][0]['digital_files'])->toHaveCount(2)
        ->and($staff['variants'][0]['digital_files'][0]['label'])->toBe('Lighting guide PDF')
        ->and($staff['variants'][0]['digital_files'][0]['version'])->toBe(1)
        ->and($staff['digital_assets'])->toHaveCount(2);
    $staffJson = json_encode($staff, JSON_THROW_ON_ERROR);
    expect($staffJson)->not->toContain('original_key')
        ->and($staffJson)->not->toContain('media/'.$first['id'])
        ->and($staffJson)->not->toContain('/original');
    $public = app(CatalogReader::class)->publicProduct('studio-guide');
    expect($public['type'])->toBe('digital')
        ->and($public['variants'][0]['max_quantity'])->toBe(1)
        ->and($public['variants'][0]['digital_files'])->toHaveCount(2)
        ->and($public['variants'][0]['digital_files'][0])->toHaveKeys(['label', 'version', 'bytes', 'content_type'])
        ->and($public['variants'][0]['digital_files'][0])->not->toHaveKeys(['id', 'asset_id', 'checksum', 'original_key', 'status']);
    $publicJson = json_encode($public, JSON_THROW_ON_ERROR);
    $originalKey = (string) DB::table('media_assets')->where('id', DB::table('digital_asset_versions')->where('id', $first['id'])->value('media_asset_id'))->value('original_key');
    expect($publicJson)->not->toContain($originalKey)
        ->and($publicJson)->not->toContain('original_key')
        ->and($publicJson)->not->toContain('checksum')
        ->and($publicJson)->not->toContain('/original');
    $this->get('/')->assertStatus(503)->assertDontSee('Studio Lighting Guide');
    $this->get('/products/studio-guide')->assertOk()
        ->assertSee('Studio Lighting Guide')
        ->assertSee('Digital download')
        ->assertSee('Lighting guide PDF')
        ->assertSee('Quantity one')
        ->assertSee('Add to cart')
        ->assertDontSee($originalKey);
    $this->get('/storage/'.$originalKey)->assertNotFound();
});

it('CatalogDigitalAsset enforces quantity one, processing states, and refuses physical inventory tracking', function (): void {
    $owner = catalogInstalledOwner();
    $digital = app(SaveProduct::class)->save($owner, catalogDigitalProductInput(catalogReadyImage($owner->id), ['status' => 'published']));
    $physical = app(SaveProduct::class)->save($owner, catalogProductInput(catalogReadyImage($owner->id), [
        'slug' => 'oak-desk', 'sku' => 'DESK-01', 'status' => 'published',
    ]));
    $digitalVariant = (string) DB::table('product_variants')->where('product_id', $digital['id'])->value('id');
    $physicalVariant = (string) DB::table('product_variants')->where('product_id', $physical['id'])->value('id');
    expect(fn () => app(SaveProduct::class)->save($owner, catalogDigitalProductInput(catalogReadyImage($owner->id), [
        'id' => $digital['id'], 'version' => $digital['version'], 'type' => 'physical',
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(AdjustStock::class)->adjust($owner, [
        'variant_id' => $digitalVariant,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 4,
        'low_stock_threshold' => 1,
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(SaveVariants::class)->save($owner, [
        'id' => $digital['id'],
        'version' => $digital['version'],
        'options' => [],
        'variants' => [[
            'id' => $digitalVariant, 'sku' => 'GUIDE-001', 'price_amount' => 899,
            'is_default' => true, 'available' => true, 'options' => [],
            'weight_grams' => 120, 'length_mm' => 10, 'width_mm' => 10, 'height_mm' => 10,
        ]],
    ]))->toThrow(ValidationException::class);
    $pending = app(VersionDigitalAsset::class)->version($owner, [
        'product_id' => $digital['id'],
        'label' => 'Pending scan',
        'file' => catalogPrivateTextFile(),
    ]);
    DB::table('digital_asset_versions')->where('id', $pending['id'])->update(['status' => 'processing']);
    expect(fn () => app(VersionDigitalAsset::class)->attach($owner, [
        'product_id' => $digital['id'],
        'variant_id' => $digitalVariant,
        'version_ids' => [$pending['id']],
    ]))->toThrow(ValidationException::class);
    DB::table('digital_asset_versions')->where('id', $pending['id'])->update(['status' => 'quarantined']);
    expect(fn () => app(VersionDigitalAsset::class)->attach($owner, [
        'product_id' => $digital['id'],
        'variant_id' => $digitalVariant,
        'version_ids' => [$pending['id']],
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(VersionDigitalAsset::class)->version($owner, [
        'product_id' => $physical['id'],
        'label' => 'Should not attach',
        'file' => catalogPrivateTextFile(),
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(VersionDigitalAsset::class)->attach($owner, [
        'product_id' => $physical['id'],
        'variant_id' => $physicalVariant,
        'version_ids' => [$pending['id']],
    ]))->toThrow(ValidationException::class);
    $ready = app(VersionDigitalAsset::class)->version($owner, [
        'product_id' => $digital['id'],
        'label' => 'Ready guide',
        'file' => catalogPrivateTextFile('ready.txt'),
    ]);
    app(VersionDigitalAsset::class)->attach($owner, [
        'product_id' => $digital['id'],
        'variant_id' => $digitalVariant,
        'version_ids' => [$ready['id']],
    ]);
    DB::table('digital_asset_versions')->where('id', $ready['id'])->update(['status' => 'quarantined']);
    $public = app(CatalogReader::class)->publicProduct('studio-guide');
    expect($public['variants'][0]['digital_files'])->toBe([]);
    $staff = app(CatalogReader::class)->staffProduct($digital['id']);
    expect($staff['variants'][0]['digital_files'][0]['status'])->toBe('quarantined');
});

it('CatalogDigitalAsset streams a large private replacement without exposing keys or copying bytes into public storage', function (): void {
    $owner = catalogInstalledOwner();
    $saved = app(SaveProduct::class)->save($owner, catalogDigitalProductInput(catalogReadyImage($owner->id), ['status' => 'published']));
    $path = sys_get_temp_dir().'/mrktflow-digital-'.Str::uuid().'.txt';
    $handle = fopen($path, 'wb');
    expect($handle)->not->toBeFalse();
    for ($i = 0; $i < 16; $i++) {
        fwrite($handle, str_repeat("fictional-guide-chunk-{$i}\n", 8000));
    }
    fclose($handle);
    expect(filesize($path))->toBeGreaterThan(2_000_000);
    $file = new UploadedFile($path, 'large-guide.txt', 'text/plain', null, true);
    $baseline = memory_get_peak_usage(true);
    $versioned = app(VersionDigitalAsset::class)->version($owner, [
        'product_id' => $saved['id'],
        'label' => 'Large lighting guide',
        'file' => $file,
    ]);
    expect(memory_get_peak_usage(true) - $baseline)->toBeLessThan(6 * 1024 * 1024);
    $mediaId = (string) DB::table('digital_asset_versions')->where('id', $versioned['id'])->value('media_asset_id');
    $originalKey = (string) DB::table('media_assets')->where('id', $mediaId)->value('original_key');
    expect(Storage::disk('local')->exists($originalKey))->toBeTrue()
        ->and(Storage::disk('public')->exists($originalKey))->toBeFalse()
        ->and((int) DB::table('media_assets')->where('id', $mediaId)->value('bytes'))->toBe((int) filesize($path));
    $variantId = (string) DB::table('product_variants')->where('product_id', $saved['id'])->value('id');
    app(VersionDigitalAsset::class)->attach($owner, [
        'product_id' => $saved['id'],
        'variant_id' => $variantId,
        'version_ids' => [$versioned['id']],
    ]);
    $encoded = json_encode(app(CatalogReader::class)->publicProduct($saved['id']), JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain($originalKey)->and($encoded)->not->toContain(file_get_contents($path) ?: 'missing');
    @unlink($path);
});

it('CatalogDigitalAsset HTTP keeps privileged staff behind two-factor and rejects customers', function (): void {
    $owner = catalogInstalledOwner();
    $saved = app(SaveProduct::class)->save($owner, catalogDigitalProductInput(catalogReadyImage($owner->id)));
    $variantId = (string) DB::table('product_variants')->where('product_id', $saved['id'])->value('id');
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/products/'.$saved['id'])->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/products/'.$saved['id'].'/digital-assets', ['label' => 'Denied'])->assertForbidden();
    $this->putJson('/admin/products/'.$saved['id'].'/variants/'.$variantId.'/digital-assets', ['version_ids' => []])->assertForbidden();
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->postJson('/admin/products/'.$saved['id'].'/digital-assets', ['label' => 'Denied'])
        ->assertUnauthorized();
});

it('CatalogDigitalAsset HTTP lets authorized staff upload attach and replace a private file', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->post('/admin/products', [
        'product' => [
            'title' => 'Studio Lighting Guide', 'slug' => 'studio-guide',
            'description' => 'A fictional downloadable lighting guide.',
            'status' => 'published', 'sku' => 'GUIDE-001', 'price_amount' => 899,
            'image_alt' => 'Cover of the studio lighting guide', 'type' => 'digital',
        ],
        'image' => UploadedFile::fake()->image('cover.png', 40, 40),
    ])->assertRedirect();
    $productId = (string) DB::table('products')->value('id');
    $variantId = (string) DB::table('product_variants')->where('product_id', $productId)->value('id');
    expect(DB::table('products')->value('type'))->toBe('digital');
    $this->get('/admin/products/'.$productId)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ProductEdit')
        ->where('product.type', 'digital')
        ->where('product.variants.0.max_quantity', 1)
        ->where('product.digital_assets', []));
    $this->post('/admin/products/'.$productId.'/digital-assets', [
        'label' => 'Lighting guide',
        'file' => catalogPrivateTextFile(),
    ])->assertRedirect('/admin/products/'.$productId);
    $versionId = (string) DB::table('digital_asset_versions')->value('id');
    $this->put('/admin/products/'.$productId.'/variants/'.$variantId.'/digital-assets', [
        'version_ids' => [$versionId],
    ])->assertRedirect('/admin/products/'.$productId);
    $this->get('/admin/products/'.$productId)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('product.variants.0.digital_files.0.label', 'Lighting guide')
        ->where('product.variants.0.digital_files.0.version', 1)
        ->has('product.digital_assets', 1));
    $this->from('/admin/products/'.$productId)->post('/admin/products/'.$productId.'/digital-assets', [
        'label' => '',
        'file' => catalogPrivateTextFile(),
    ])->assertRedirect('/admin/products/'.$productId)->assertSessionHasErrors();
    foreach (['content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()))
            ->postJson('/admin/products/'.$productId.'/digital-assets', ['label' => 'Denied'])
            ->assertForbidden();
    }
});

it('CatalogDigitalAsset HTTP rejects forged uploads and does not flash nested file payloads', function (): void {
    $owner = catalogInstalledOwner();
    $saved = app(SaveProduct::class)->save($owner, catalogDigitalProductInput(catalogReadyImage($owner->id)));
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->app->instance('env', 'local');
    $this->postJson('/admin/products/'.$saved['id'].'/digital-assets', ['label' => 'Forged'])->assertStatus(419);
    $this->app->instance('env', 'testing');
    $this->from('/admin/products/'.$saved['id'])->post('/admin/products/'.$saved['id'].'/digital-assets', [
        'label' => '',
        'file' => catalogPrivateTextFile(),
    ])->assertRedirect('/admin/products/'.$saved['id'])->assertSessionHasErrors()->assertSessionMissing('_old_input.file');
});

it('CatalogDigitalAsset demo seeder adds fictional physical and digital products without production seeding', function (): void {
    $owner = catalogInstalledOwner();
    (new DemoStoreSeeder)->run();
    expect(DB::table('products')->where('slug', 'studio-lamp')->value('type'))->toBe('physical')
        ->and(DB::table('products')->where('slug', 'studio-guide')->value('type'))->toBe('digital')
        ->and(DB::table('digital_asset_versions')->count())->toBeGreaterThan(0)
        ->and(DB::table('customer_accounts')->where('email', 'demo-shopper@example.test')->exists())->toBeTrue()
        ->and(DB::table('cart_lines')->count())->toBe(2);
    $public = app(CatalogReader::class)->publicProduct('studio-guide');
    expect(json_encode($public, JSON_THROW_ON_ERROR))->not->toContain('original_key');
    (new DemoStoreSeeder)->run();
    expect(DB::table('products')->whereIn('slug', ['studio-lamp', 'studio-guide'])->count())->toBe(2);
    (new DatabaseSeeder)->run();
    expect(DB::table('products')->count())->toBe(2);
    $this->app->instance('env', 'production');
    try {
        expect(fn () => (new DemoStoreSeeder)->run())->toThrow(RuntimeException::class);
    } finally {
        $this->app->instance('env', 'testing');
    }
    expect(DB::table('staff_accounts')->where('id', $owner->id)->exists())->toBeTrue();
});
