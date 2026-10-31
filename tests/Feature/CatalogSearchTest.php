<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveProductSchema;
use App\Domain\Catalog\Actions\SearchCatalog;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\StaffPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

it('CatalogSearch matches published titles and SKUs and keeps drafts out of results', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'title' => 'Hidden Draft Lamp', 'slug' => 'hidden-draft-lamp', 'sku' => 'DRAFT-LAMP', 'status' => 'draft',
    ]));
    app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'title' => 'Oak Desk', 'slug' => 'oak-desk', 'sku' => 'DESK-01', 'price_amount' => 12900, 'status' => 'published',
        'image_alt' => 'Oak desk from the front',
    ]));
    $titleHits = app(SearchCatalog::class)->search(['q' => 'Studio']);
    expect(array_column($titleHits['products'], 'title'))->toBe(['Studio Lamp']);
    expect($titleHits['meta']['totalItems'])->toBe(1);
    $skuHits = app(SearchCatalog::class)->search(['q' => 'desk-01']);
    expect(array_column($skuHits['products'], 'slug'))->toBe(['oak-desk']);
    $percent = app(SearchCatalog::class)->search(['q' => '%']);
    expect($percent['products'])->toBe([]);
    $this->get('/search?q=Studio')->assertOk()->assertSee('Studio Lamp')->assertDontSee('Hidden Draft Lamp')->assertDontSee('Add to cart');
    $this->get('/')->assertOk()->assertSee('Studio Lamp');
});

it('CatalogSearch filters by category price and availability without leaking unpublished categories', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $lighting = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $lamps = app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'title' => 'Lamps', 'slug' => 'lamps', 'parent_id' => $lighting['id'], 'status' => 'published', 'description' => 'Task lamps.',
    ]));
    $secret = app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'title' => 'Secret stock', 'slug' => 'secret-stock', 'status' => 'draft', 'description' => 'Unpublished inventory.',
    ]));
    $lamp = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'category_ids' => [$lamps['id']],
    ]));
    app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'title' => 'Oak Desk', 'slug' => 'oak-desk', 'sku' => 'DESK-01', 'price_amount' => 12900, 'status' => 'published',
        'image_alt' => 'Oak desk from the front', 'category_ids' => [$lighting['id']],
    ]));
    $tracked = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'title' => 'Empty Shelf Lamp', 'slug' => 'empty-shelf-lamp', 'sku' => 'LAMP-OUT', 'price_amount' => 1999, 'status' => 'published',
        'image_alt' => 'Empty shelf lamp', 'category_ids' => [$lamps['id']],
    ]));
    $trackedVariant = (string) DB::table('product_variants')->where('product_id', $tracked['id'])->value('id');
    app(AdjustStock::class)->adjust($owner, [
        'variant_id' => $trackedVariant,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 0,
        'low_stock_threshold' => 1,
    ]);
    app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'title' => 'Hidden Category Lamp', 'slug' => 'hidden-category-lamp', 'sku' => 'LAMP-HID',
        'price_amount' => 4100, 'status' => 'published', 'category_ids' => [$secret['id']],
    ]));
    $inLamps = app(SearchCatalog::class)->search(['category' => 'lighting/lamps']);
    expect(array_column($inLamps['products'], 'slug'))->toEqualCanonicalizing(['studio-lamp', 'empty-shelf-lamp']);
    $inLighting = app(SearchCatalog::class)->search(['category' => 'lighting']);
    expect(array_column($inLighting['products'], 'slug'))->toEqualCanonicalizing(['studio-lamp', 'oak-desk', 'empty-shelf-lamp']);
    $price = app(SearchCatalog::class)->search(['price_min' => 2000, 'price_max' => 3000]);
    expect(array_column($price['products'], 'slug'))->toBe(['studio-lamp']);
    $available = app(SearchCatalog::class)->search(['available' => '1']);
    expect(array_column($available['products'], 'slug'))->not->toContain('empty-shelf-lamp');
    expect(array_column($available['products'], 'slug'))->toContain('studio-lamp');
    $secretHits = app(SearchCatalog::class)->search(['category' => 'secret-stock']);
    expect($secretHits['products'])->toBe([]);
    expect(array_column($inLighting['categories'], 'path'))->not->toContain('secret-stock');
    $this->get('/search?category=secret-stock')->assertOk()->assertDontSee('Hidden Category Lamp')->assertDontSee('Secret stock');
    expect($lamp['id'])->not->toBe('');
});

it('CatalogSearch typed facets exclude drafts from values and counts', function (): void {
    $owner = catalogInstalledOwner();
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    $image = catalogReadyImage($owner->id);
    app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published',
        'schema_id' => $schema['id'],
        'attributes' => ['sensor.megapixels' => '24.2', 'lens.mount' => 'rf', 'body.stabilized' => true],
    ]));
    app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'title' => 'Draft Mirrorless', 'slug' => 'draft-mirrorless', 'sku' => 'CAM-DRAFT', 'status' => 'draft',
        'schema_id' => $schema['id'],
        'attributes' => ['sensor.megapixels' => '8', 'lens.mount' => 'e', 'body.stabilized' => false],
    ]));
    $result = app(SearchCatalog::class)->search([]);
    $mount = collect($result['facets'])->firstWhere('key', 'lens.mount');
    expect($mount['values'])->toBe([['value' => 'rf', 'count' => 1]]);
    $stabilized = collect($result['facets'])->firstWhere('key', 'body.stabilized');
    expect($stabilized['values'])->toBe([['value' => '1', 'count' => 1]]);
    $rfOnly = app(SearchCatalog::class)->search(['f' => ['lens.mount' => 'e']]);
    expect($rfOnly['products'])->toBe([]);
    $this->get('/search?f[lens.mount]=e')->assertOk()->assertDontSee('Draft Mirrorless')->assertSee('No products match this search.');
});

it('CatalogSearch sorts numeric attributes numerically and paginates with stable query state', function (): void {
    $owner = catalogInstalledOwner();
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    $image = catalogReadyImage($owner->id);
    foreach ([
        ['title' => 'Eight Sensor', 'slug' => 'eight-sensor', 'sku' => 'CAM-8', 'megapixels' => '8', 'price' => 800],
        ['title' => 'Thirty Two Sensor', 'slug' => 'thirty-two-sensor', 'sku' => 'CAM-32', 'megapixels' => '32.5', 'price' => 3200],
        ['title' => 'Twenty Four Sensor', 'slug' => 'twenty-four-sensor', 'sku' => 'CAM-24', 'megapixels' => '24.2', 'price' => 2400],
    ] as $camera) {
        app(SaveProduct::class)->save($owner, catalogProductInput($image, [
            'title' => $camera['title'], 'slug' => $camera['slug'], 'sku' => $camera['sku'],
            'price_amount' => $camera['price'], 'status' => 'published', 'schema_id' => $schema['id'],
            'image_alt' => $camera['title'].' body',
            'attributes' => ['sensor.megapixels' => $camera['megapixels'], 'lens.mount' => 'rf'],
        ]));
    }
    $numeric = app(SearchCatalog::class)->search(['sort' => 'sensor.megapixels', 'page_size' => 10]);
    expect(array_column($numeric['products'], 'slug'))->toBe(['eight-sensor', 'twenty-four-sensor', 'thirty-two-sensor']);
    $pageOne = app(SearchCatalog::class)->search(['sort' => 'sensor.megapixels', 'page' => 1, 'page_size' => 1]);
    $pageTwo = app(SearchCatalog::class)->search(['sort' => 'sensor.megapixels', 'page' => 2, 'page_size' => 1]);
    expect(array_column($pageOne['products'], 'slug'))->toBe(['eight-sensor']);
    expect(array_column($pageTwo['products'], 'slug'))->toBe(['twenty-four-sensor']);
    expect($pageTwo['meta'])->toMatchArray(['page' => 2, 'pageSize' => 1, 'totalItems' => 3, 'totalPages' => 3]);
    $page = $this->get('/search?sort=sensor.megapixels&page=2&page_size=1&q=Sensor');
    $page->assertOk()->assertSee('Twenty Four Sensor')->assertDontSee('Eight Sensor')->assertDontSee('Thirty Two Sensor');
    $page->assertSee('name="q"', false)->assertSee('value="Sensor"', false);
    $page->assertSee('sort=sensor.megapixels', false)->assertSee('page=1', false)->assertSee('q=Sensor', false);
    $page->assertSee('name="robots" content="noindex, nofollow"', false);
    $this->get('/search?sort=products.status&q=Sensor')->assertOk()->assertDontSee('drop table', false);
});

it('CatalogSearch ignores unsafe sort fields oversized queries and unknown facets', function (): void {
    $owner = catalogInstalledOwner();
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput([
        'attributes' => [
            [
                'key' => 'sensor.megapixels', 'type' => 'decimal', 'unit' => 'MP', 'required' => true,
                'comparable' => true, 'filterable' => true, 'label' => 'Megapixels',
            ],
            [
                'key' => 'body.notes', 'type' => 'string', 'required' => false, 'comparable' => false,
                'filterable' => false, 'label' => 'Internal notes',
            ],
        ],
    ]));
    $image = catalogReadyImage($owner->id);
    app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'schema_id' => $schema['id'],
        'attributes' => ['sensor.megapixels' => '24.2', 'body.notes' => 'warehouse aisle 4'],
    ]));
    $unsafe = app(SearchCatalog::class)->search([
        'sort' => 'products.status',
        'q' => str_repeat('a', 200),
        'page' => 0,
        'page_size' => 500,
        'f' => ['body.notes' => 'warehouse aisle 4', 'unknown.secret' => '1'],
    ]);
    expect($unsafe['query']['sort'])->toBe('title');
    expect($unsafe['query']['q'])->toBe('');
    expect($unsafe['query']['page'])->toBe(1);
    expect($unsafe['query']['page_size'])->toBe(24);
    expect($unsafe['query']['facets'])->toBe([]);
    expect(array_column($unsafe['products'], 'slug'))->toBe(['studio-lamp']);
    expect(collect($unsafe['facets'])->firstWhere('key', 'body.notes'))->toBeNull();
    $this->get('/search?sort=id&f[body.notes]=warehouse+aisle+4')->assertOk()->assertSee('Studio Lamp');
});

it('CatalogSearch HTTP keeps privileged staff behind two-factor and denies outsiders catalog writes', function (): void {
    $owner = catalogInstalledOwner();
    $this->get('/search')->assertOk()->assertSee('Search')->assertDontSee('Add to cart');
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/products')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/products', ['product' => ['title' => 'Denied']])->assertForbidden();
    $this->flushSession();
    $this->app['auth']->forgetGuards();
    $customer = new CustomerAccount;
    $customer->forceFill(['name' => 'Shopper', 'email' => 'shopper@example.test', 'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1])->save();
    $this->actingAs($customer, 'customer')->postJson('/admin/products', ['product' => catalogProductInput(catalogReadyImage($owner->id))])->assertUnauthorized();
    foreach (['content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()))
            ->postJson('/admin/products', ['product' => catalogProductInput(catalogReadyImage($owner->id), ['slug' => $role, 'sku' => strtoupper($role)])])
            ->assertForbidden();
    }
});
