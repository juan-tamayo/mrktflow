<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\ArchiveProduct;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\ReadCart;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Exceptions\CartConflict;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use Database\Seeders\DemoStoreSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\CustomerRegistration;

uses(DatabaseMigrations::class);
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

/**
 * @return array{owner: StaffAccount, physical_id: string, physical_variant: string, digital_id: string, digital_variant: string}
 */
function cartCatalog(): array
{
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $physical = app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    $physicalVariant = (string) DB::table('product_variants')->where('product_id', $physical['id'])->value('id');
    $digital = app(SaveProduct::class)->save($owner, catalogDigitalProductInput($image, ['status' => 'published']));
    $digitalVariant = (string) DB::table('product_variants')->where('product_id', $digital['id'])->value('id');
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

    return [
        'owner' => $owner,
        'physical_id' => $physical['id'],
        'physical_variant' => $physicalVariant,
        'digital_id' => $digital['id'],
        'digital_variant' => $digitalVariant,
    ];
}

function cartCustomer(array $overrides = []): CustomerAccount
{
    return app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), $overrides));
}

it('CheckoutOrdersCart lets guests and accounts replace quantities while ignoring supplied prices', function (): void {
    $catalog = cartCatalog();
    $guest = CartOwner::guest('guest-cart-token');
    $empty = app(ReadCart::class)->snapshot($guest);
    expect($empty['revision'])->toBe(1)->and($empty['lines'])->toBe([]);
    $added = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 2,
        'expected_revision' => 1,
        'price_amount' => 1,
        'unit_price' => 1,
        'amount' => 1,
    ]);
    expect($added['revision'])->toBe(2)
        ->and($added['lines'])->toHaveCount(1)
        ->and($added['lines'][0]['quantity'])->toBe(2)
        ->and($added['lines'][0]['variant_id'])->toBe($catalog['physical_variant'])
        ->and($added['lines'][0]['money']['amount'])->toBe(2499)
        ->and($added['subtotal']['amount'])->toBe(4998);
    expect(json_encode($added, JSON_THROW_ON_ERROR))->not->toContain('guest-cart-token', 'guest_token_hash', 'password');
    $replaced = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 1,
        'expected_revision' => 2,
    ]);
    expect($replaced['lines'][0]['quantity'])->toBe(1)->and($replaced['subtotal']['amount'])->toBe(2499);

    $customer = cartCustomer();
    $account = CartOwner::customer($customer->id);
    $accountCart = app(UpdateCart::class)->replaceLine($account, [
        'variant_id' => $catalog['digital_variant'],
        'quantity' => 1,
        'expected_revision' => 1,
        'price_amount' => 50,
    ]);
    expect($accountCart['lines'])->toHaveCount(1)
        ->and($accountCart['lines'][0]['quantity'])->toBe(1)
        ->and($accountCart['lines'][0]['money']['amount'])->toBe(899)
        ->and($accountCart['subtotal']['amount'])->toBe(899);
    expect(app(ReadCart::class)->snapshot($guest)['lines'][0]['variant_id'])->toBe($catalog['physical_variant']);
    expect(app(ReadCart::class)->snapshot($account)['lines'][0]['variant_id'])->toBe($catalog['digital_variant']);
});

it('CheckoutOrdersCart rejects invalid unavailable digital-over-one and stale mutations', function (): void {
    $catalog = cartCatalog();
    $guest = CartOwner::guest('guest-cart-token');
    expect(fn () => app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 2,
        'expected_revision' => 99,
    ]))->toThrow(CartConflict::class);
    $first = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 1,
        'expected_revision' => 1,
    ]);
    expect(fn () => app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['digital_variant'],
        'quantity' => 2,
        'expected_revision' => $first['revision'],
    ]))->toThrow(ValidationException::class);

    $soldOut = app(SaveProduct::class)->save($catalog['owner'], catalogProductInput(catalogReadyImage($catalog['owner']->id), [
        'title' => 'Empty Shelf Lamp',
        'slug' => 'empty-shelf-lamp',
        'sku' => 'LAMP-OUT',
        'price_amount' => 1999,
        'status' => 'published',
        'image_alt' => 'Empty shelf lamp',
    ]));
    $soldOutVariant = (string) DB::table('product_variants')->where('product_id', $soldOut['id'])->value('id');
    app(AdjustStock::class)->adjust($catalog['owner'], [
        'variant_id' => $soldOutVariant,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 0,
        'low_stock_threshold' => 1,
    ]);
    expect(fn () => app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $soldOutVariant,
        'quantity' => 1,
        'expected_revision' => $first['revision'],
    ]))->toThrow(ValidationException::class);

    $draft = app(SaveProduct::class)->save($catalog['owner'], catalogProductInput(catalogReadyImage($catalog['owner']->id), [
        'slug' => 'draft-lamp', 'sku' => 'LAMP-DRAFT',
    ]));
    $draftVariant = (string) DB::table('product_variants')->where('product_id', $draft['id'])->value('id');
    expect(fn () => app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $draftVariant,
        'quantity' => 1,
        'expected_revision' => $first['revision'],
    ]))->toThrow(ValidationException::class);

    $held = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 1,
        'expected_revision' => $first['revision'],
    ]);
    app(ArchiveProduct::class)->archive($catalog['owner'], [
        'id' => $catalog['physical_id'],
        'version' => (int) DB::table('products')->where('id', $catalog['physical_id'])->value('version'),
    ]);
    $snapshot = app(ReadCart::class)->snapshot($guest);
    expect($snapshot['lines'][0]['available'])->toBeFalse()
        ->and($snapshot['notices'][0])->toContain('no longer available');
    expect(fn () => app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 2,
        'expected_revision' => $held['revision'],
    ]))->toThrow(ValidationException::class);
    $removed = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 0,
        'expected_revision' => $held['revision'],
    ]);
    expect($removed['lines'])->toBe([]);
});

it('CheckoutOrdersCart HTTP keeps guest and account carts revision-checked and denies other sessions', function (): void {
    $catalog = cartCatalog();
    $this->get('/')->assertStatus(503)->assertDontSee('Studio Lamp');
    $empty = $this->getJson('/store-api/v1/cart')->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.lines', []);
    expect($empty->headers->get('Cache-Control'))->toContain('no-store');
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['physical_variant'],
        'quantity' => 2,
        'expectedRevision' => 1,
        'priceAmount' => 1,
    ])->assertOk()->assertJsonPath('data.lines.0.quantity', 2)
        ->assertJsonPath('data.lines.0.money.amount', 2499)
        ->assertJsonPath('data.subtotal.amount', 4998);
    $stale = $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['physical_variant'],
        'quantity' => 1,
        'expectedRevision' => 1,
    ])->assertStatus(409);
    expect($stale->json('error.code'))->toBe('CONFLICT');
    $cart = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    $lineId = $cart['lines'][0]['id'];
    $this->patchJson('/store-api/v1/cart/lines/'.$lineId, [
        'quantity' => 1,
        'expectedRevision' => $cart['revision'],
    ])->assertOk()->assertJsonPath('data.lines.0.quantity', 1);

    $alice = cartCustomer();
    $bob = cartCustomer(['email' => 'bob@example.test']);
    $this->actingAs($alice, 'customer')->withSession(['customer_session_version' => 1]);
    $aliceCart = $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['digital_variant'],
        'quantity' => 1,
        'expectedRevision' => 1,
    ])->assertOk()->json('data');
    expect($aliceCart['lines'])->toHaveCount(1);
    $this->actingAs($bob, 'customer')->withSession(['customer_session_version' => 1]);
    $this->getJson('/store-api/v1/cart')->assertOk()->assertJsonPath('data.lines', []);
    $this->patchJson('/store-api/v1/cart/lines/'.$aliceCart['lines'][0]['id'], [
        'quantity' => 1,
        'expectedRevision' => 1,
    ])->assertNotFound();
    $this->deleteJson('/store-api/v1/cart/lines/'.$aliceCart['lines'][0]['id'], [
        'expectedRevision' => 1,
    ])->assertNotFound();
    $this->actingAs($alice, 'customer')->withSession(['customer_session_version' => 1]);
    $this->getJson('/store-api/v1/cart')->assertOk()->assertJsonPath('data.lines.0.variantId', $catalog['digital_variant']);

    $this->actingAs($catalog['owner'], 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/products')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/products', ['product' => ['title' => 'Denied']])->assertForbidden();
});

it('CheckoutOrdersCart HTTP renders native cart forms and rejects CSRF or unavailable variants', function (): void {
    $catalog = cartCatalog();
    $this->get('/products/studio-lamp')->assertOk()
        ->assertSee('Add to cart')
        ->assertSee('name="variant_id"', false)
        ->assertSee('name="expected_revision"', false)
        ->assertDontSee('Buy now');
    $this->get('/products/studio-guide')->assertOk()->assertSee('Add to cart');
    $this->from('/products/studio-lamp')->post('/cart/lines', [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 1,
        'expected_revision' => 1,
        'price_amount' => 1,
    ])->assertRedirect('/cart');
    $page = $this->get('/cart');
    $page->assertOk()
        ->assertSee('Studio Lamp')
        ->assertSee('USD 24.99')
        ->assertSee('name="quantity"', false)
        ->assertDontSee('guest_token_hash')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $lineId = (string) DB::table('cart_lines')->value('id');
    $revision = (int) DB::table('carts')->value('revision');
    $this->from('/cart')->post('/cart/lines/'.$lineId, [
        'quantity' => 2,
        'expected_revision' => $revision,
    ])->assertRedirect('/cart');
    expect((int) DB::table('cart_lines')->value('quantity'))->toBe(2);
    $this->from('/products/studio-guide')->post('/cart/lines', [
        'variant_id' => $catalog['digital_variant'],
        'quantity' => 1,
        'expected_revision' => (int) DB::table('carts')->value('revision'),
    ])->assertRedirect('/cart');
    $this->get('/cart')->assertSee('Studio Lamp')->assertSee('Studio Lighting Guide');
    $this->app->instance('env', 'local');
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['physical_variant'],
        'quantity' => 1,
        'expectedRevision' => 1,
    ])->assertStatus(419);
    $this->app->instance('env', 'testing');
    $this->from('/products/studio-lamp')->post('/cart/lines', [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 1,
        'expected_revision' => 1,
    ])->assertRedirect('/products/studio-lamp')->assertSessionHasErrors();
});

it('CheckoutOrdersCart demo seeder creates a fictional mixed cart without production seeding', function (): void {
    catalogInstalledOwner();
    (new DemoStoreSeeder)->run();
    expect(DB::table('products')->where('slug', 'studio-lamp')->value('type'))->toBe('physical')
        ->and(DB::table('products')->where('slug', 'studio-guide')->value('type'))->toBe('digital')
        ->and(DB::table('customer_accounts')->where('email', 'demo-shopper@example.test')->exists())->toBeTrue();
    $cart = DB::table('carts')->where('customer_id', DB::table('customer_accounts')->where('email', 'demo-shopper@example.test')->value('id'))->first();
    expect($cart)->not->toBeNull();
    $types = DB::table('cart_lines')
        ->join('product_variants', 'cart_lines.variant_id', '=', 'product_variants.id')
        ->join('products', 'product_variants.product_id', '=', 'products.id')
        ->where('cart_lines.cart_id', $cart->id)
        ->orderBy('products.type')
        ->pluck('products.type')
        ->all();
    expect($types)->toBe(['digital', 'physical']);
    (new DemoStoreSeeder)->run();
    expect(DB::table('carts')->count())->toBe(1)->and(DB::table('cart_lines')->count())->toBe(2);
    $this->app->instance('env', 'production');
    try {
        expect(fn () => (new DemoStoreSeeder)->run())->toThrow(RuntimeException::class);
    } finally {
        $this->app->instance('env', 'testing');
    }
});
