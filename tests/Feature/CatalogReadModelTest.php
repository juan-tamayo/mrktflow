<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\ArchiveProduct;
use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveVariants;
use App\Domain\Catalog\Actions\SearchCatalog;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\Catalog\Data\ProductView;
use App\Domain\Catalog\Exceptions\CatalogProductNotFound;
use App\Domain\Catalog\Exceptions\CatalogProductUnpublished;
use App\Domain\Identity\Models\CustomerAccount;
use App\Http\Resources\ProductResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

it('CatalogReadModel exposes physical and digital sellability without leaking stock quantities', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $lighting = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $inStock = catalogTrackedVariant($owner, 2, 1);
    app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'id' => $inStock['product_id'],
        'version' => $inStock['version'],
        'status' => 'published',
        'category_ids' => [$lighting['id']],
    ]));
    $soldOut = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'title' => 'Empty Shelf Lamp',
        'slug' => 'empty-shelf-lamp',
        'sku' => 'LAMP-OUT',
        'price_amount' => 1999,
        'status' => 'published',
        'image_alt' => 'Empty shelf lamp',
        'category_ids' => [$lighting['id']],
    ]));
    $soldOutVariant = (string) DB::table('product_variants')->where('product_id', $soldOut['id'])->value('id');
    app(AdjustStock::class)->adjust($owner, [
        'variant_id' => $soldOutVariant,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 0,
        'low_stock_threshold' => 1,
    ]);
    $matrix = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'title' => 'Studio Lamp Matrix',
        'slug' => 'studio-lamp-matrix',
        'sku' => 'LAMP-MX',
        'status' => 'published',
        'category_ids' => [$lighting['id']],
    ]));
    $pine = catalogReadyImage($owner->id);
    app(SaveVariants::class)->save($owner, catalogVariantMatrix($matrix['id'], $matrix['version'], $pine));
    $digital = app(SaveProduct::class)->save($owner, catalogDigitalProductInput($image, [
        'status' => 'published',
        'category_ids' => [$lighting['id']],
    ]));
    $digitalVariant = (string) DB::table('product_variants')->where('product_id', $digital['id'])->value('id');
    $unattached = app(CatalogReader::class)->publicProduct('studio-guide');
    expect($unattached['available'])->toBeFalse()
        ->and($unattached['availability'])->toBe('unavailable')
        ->and($unattached['variants'][0]['available'])->toBeFalse()
        ->and($unattached['variants'][0]['availability'])->toBe('unavailable');
    $ready = app(VersionDigitalAsset::class)->version($owner, [
        'product_id' => $digital['id'],
        'label' => 'Lighting guide',
        'file' => catalogPrivateTextFile(),
    ]);
    app(VersionDigitalAsset::class)->attach($owner, [
        'product_id' => $digital['id'],
        'variant_id' => $digitalVariant,
        'version_ids' => [$ready['id']],
    ]);

    $publicInStock = app(CatalogReader::class)->publicProduct('studio-lamp');
    expect($publicInStock['available'])->toBeTrue()
        ->and($publicInStock['availability'])->toBe('in_stock')
        ->and($publicInStock['type'])->toBe('physical')
        ->and($publicInStock['variants'][0]['available'])->toBeTrue()
        ->and($publicInStock['variants'][0]['availability'])->toBe('in_stock')
        ->and($publicInStock['variants'][0])->not->toHaveKeys(['on_hand', 'reserved', 'available_quantity', 'low_stock', 'low_stock_threshold', 'tracks_inventory']);
    expect(json_encode($publicInStock, JSON_THROW_ON_ERROR))->not->toContain('inventory_balances');

    $staff = app(CatalogReader::class)->staffProduct($inStock['product_id']);
    expect($staff['variants'][0]['available'])->toBeTrue()
        ->and($staff['variants'][0]['availability'])->toBe('in_stock')
        ->and($staff['variants'][0]['on_hand'])->toBe(2)
        ->and($staff['variants'][0]['reserved'])->toBe(0)
        ->and($staff['variants'][0]['available_quantity'])->toBe(2)
        ->and($staff['variants'][0]['low_stock'])->toBeFalse();

    $publicSoldOut = app(CatalogReader::class)->publicProduct('empty-shelf-lamp');
    expect($publicSoldOut['available'])->toBeFalse()
        ->and($publicSoldOut['availability'])->toBe('out_of_stock')
        ->and($publicSoldOut['variants'][0]['available'])->toBeFalse()
        ->and($publicSoldOut['variants'][0]['availability'])->toBe('out_of_stock');
    $staffSoldOut = app(CatalogReader::class)->staffProduct($soldOut['id']);
    expect($staffSoldOut['variants'][0]['low_stock'])->toBeTrue()
        ->and($staffSoldOut['variants'][0]['available_quantity'])->toBe(0)
        ->and($staffSoldOut['variants'][0]['availability'])->toBe('out_of_stock');

    $publicMatrix = app(CatalogReader::class)->publicProduct('studio-lamp-matrix');
    $unavailable = collect($publicMatrix['variants'])->firstOrFail(fn (array $variant): bool => $variant['sku'] === 'LAMP-PINE-M');
    expect($unavailable['available'])->toBeFalse()->and($unavailable['availability'])->toBe('unavailable');

    $publicDigital = app(CatalogReader::class)->publicProduct('studio-guide');
    expect($publicDigital['available'])->toBeTrue()
        ->and($publicDigital['availability'])->toBe('in_stock')
        ->and($publicDigital['variants'][0]['available'])->toBeTrue()
        ->and($publicDigital['type'])->toBe('digital');

    $view = ProductView::fromPublic($publicSoldOut);
    expect($view->available)->toBeFalse()
        ->and($view->availability)->toBe('out_of_stock')
        ->and($view->variants[0]['availability'])->toBe('out_of_stock');
    $resource = (new ProductResource($view))->resolve();
    expect($resource['available'])->toBeFalse()
        ->and($resource['availability'])->toBe('out_of_stock')
        ->and($resource['variants'][0])->not->toHaveKeys(['on_hand', 'reserved', 'low_stock']);

    $category = app(CatalogReader::class)->publicCategory('lighting');
    $cards = collect($category['products'])->keyBy('slug');
    expect($cards['studio-lamp']['available'])->toBeTrue()
        ->and($cards['studio-lamp']['availability'])->toBe('in_stock')
        ->and($cards['empty-shelf-lamp']['available'])->toBeFalse()
        ->and($cards['empty-shelf-lamp']['availability'])->toBe('out_of_stock')
        ->and($cards['studio-guide']['available'])->toBeTrue();

    $search = app(SearchCatalog::class)->search(['q' => 'Lamp', 'available' => '1']);
    expect(array_column($search['products'], 'slug'))->toContain('studio-lamp')
        ->and(array_column($search['products'], 'slug'))->not->toContain('empty-shelf-lamp')
        ->and(array_column($search['products'], 'slug'))->not->toContain('studio-guide');
    $out = app(SearchCatalog::class)->search(['available' => '0']);
    $outSlugs = array_column($out['products'], 'slug');
    expect($outSlugs)->toContain('empty-shelf-lamp')
        ->and($outSlugs)->not->toContain('studio-lamp');
    $guideSearch = collect(app(SearchCatalog::class)->search(['q' => 'Guide'])['products'])->firstOrFail(fn (array $product): bool => $product['slug'] === 'studio-guide');
    expect($guideSearch['available'])->toBeTrue()->and($guideSearch['availability'])->toBe('in_stock');

    $this->get('/products/empty-shelf-lamp')->assertOk()
        ->assertSee('This product is out of stock.')
        ->assertDontSee('Add to cart')
        ->assertDontSee('on_hand');
    $this->get('/products/studio-lamp-matrix?variant='.$unavailable['id'])->assertOk()
        ->assertSee('This combination is unavailable.')
        ->assertDontSee('<strong>USD 25.99</strong>', false);
    $this->get('/categories/lighting')->assertOk()
        ->assertSee('Empty Shelf Lamp')
        ->assertSee('Out of stock')
        ->assertSee('Studio Lamp');
    $this->get('/search?q=Empty')->assertOk()->assertSee('Out of stock');
    $this->get('/')->assertStatus(503)->assertDontSee('Studio Lamp');
});

it('CatalogReadModel degrades archived and missing products without leaking titles on HTML or JSON', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $saved = app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    $staff = app(CatalogReader::class)->staffProduct($saved['id']);
    expect($staff['status'])->toBe('published');
    $archived = app(ArchiveProduct::class)->archive($owner, ['id' => $saved['id'], 'version' => $saved['version']]);
    expect($archived['status'])->toBe('archived');
    expect(fn () => app(CatalogReader::class)->publicProduct('studio-lamp'))->toThrow(CatalogProductUnpublished::class);
    expect(fn () => app(CatalogReader::class)->publicProduct('missing-lamp'))->toThrow(CatalogProductNotFound::class);
    $this->get('/products/studio-lamp')->assertNotFound()->assertDontSee('Studio Lamp');
    $this->get('/products/missing-lamp')->assertNotFound()->assertDontSee('Studio Lamp');
    $archivedJson = $this->getJson('/store-api/v1/products/'.$saved['id']);
    $archivedJson->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND')
        ->assertJsonMissing(['title' => 'Studio Lamp']);
    expect($archivedJson->json('error.message'))->not->toContain('Studio Lamp');
    expect($archivedJson->json('error.requestId'))->toBeString()->not->toBe('');
    $missingJson = $this->getJson('/store-api/v1/products/missing-lamp');
    $missingJson->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND')->assertDontSee('Studio Lamp');
    $this->get('/')->assertStatus(503);
});

it('CatalogReadModel JSON contract matches the public catalog snapshot', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $saved = app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    $variantId = (string) DB::table('product_variants')->where('product_id', $saved['id'])->value('id');
    app(AdjustStock::class)->adjust($owner, [
        'variant_id' => $variantId,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 4,
        'low_stock_threshold' => 1,
    ]);
    $json = $this->getJson('/store-api/v1/products/'.$saved['id']);
    $json->assertOk()
        ->assertJsonPath('data.id', $saved['id'])
        ->assertJsonPath('data.slug', 'studio-lamp')
        ->assertJsonPath('data.type', 'physical')
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.availability', 'in_stock')
        ->assertJsonPath('data.variants.0.available', true)
        ->assertJsonPath('data.variants.0.availability', 'in_stock')
        ->assertJsonMissingPath('data.variants.0.on_hand')
        ->assertJsonMissingPath('data.variants.0.low_stock');
    expect($json->json('data.variants.0'))->not->toHaveKeys(['on_hand', 'reserved', 'available_quantity', 'low_stock']);
    $list = $this->getJson('/store-api/v1/products?q=Studio');
    $list->assertOk()
        ->assertJsonPath('data.0.slug', 'studio-lamp')
        ->assertJsonPath('data.0.available', true)
        ->assertJsonPath('data.0.availability', 'in_stock')
        ->assertJsonPath('meta.totalItems', 1);
    $this->get('/')->assertStatus(503);
});

it('CatalogReadModel HTTP keeps privileged staff behind two-factor and rejects customers', function (): void {
    $owner = catalogInstalledOwner();
    $saved = app(SaveProduct::class)->save($owner, catalogProductInput(catalogReadyImage($owner->id), ['status' => 'published']));
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/products')->assertRedirect('/admin/two-factor');
    $this->get('/admin/products/'.$saved['id'])->assertRedirect('/admin/two-factor');
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper',
        'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->postJson('/admin/products', ['product' => ['title' => 'Denied']])
        ->assertUnauthorized();
    $this->get('/')->assertStatus(503);
});
