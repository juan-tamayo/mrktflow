<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\CheckoutOrders\Actions\MergeCart;
use App\Domain\CheckoutOrders\Actions\ReadCart;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\CustomerRegistration;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

/**
 * @return array{owner: StaffAccount, physical_variant: string, digital_variant: string}
 */
function mergeCatalog(): array
{
    $catalog = cartCatalog();
    app(AdjustStock::class)->adjust($catalog['owner'], [
        'variant_id' => $catalog['physical_variant'],
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 5,
        'low_stock_threshold' => 1,
    ]);

    return [
        'owner' => $catalog['owner'],
        'physical_variant' => $catalog['physical_variant'],
        'digital_variant' => $catalog['digital_variant'],
    ];
}

it('CheckoutOrdersCartMerge sums matching physical lines, clamps to the allowed maximum, and keeps digital quantity one', function (): void {
    $catalog = mergeCatalog();
    $guest = CartOwner::guest('guest-merge-token');
    $customer = cartCustomer();
    $account = CartOwner::customer($customer->id);
    app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 4,
        'expected_revision' => 1,
        'price_amount' => 1,
    ]);
    $guestCart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['digital_variant'],
        'quantity' => 1,
        'expected_revision' => 2,
    ]);
    app(UpdateCart::class)->replaceLine($account, [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 3,
        'expected_revision' => 1,
        'price_amount' => 1,
    ]);
    app(UpdateCart::class)->replaceLine($account, [
        'variant_id' => $catalog['digital_variant'],
        'quantity' => 1,
        'expected_revision' => 2,
    ]);

    $merged = app(MergeCart::class)->merge($guest, $account);
    expect($merged['lines'])->toHaveCount(2);
    $physical = collect($merged['lines'])->firstWhere('variant_id', $catalog['physical_variant']);
    $digital = collect($merged['lines'])->firstWhere('variant_id', $catalog['digital_variant']);
    expect($physical['quantity'])->toBe(5)
        ->and($digital['quantity'])->toBe(1)
        ->and($merged['notices'])->toContain('Some quantities were adjusted to the allowed maximum.');
    expect(json_encode($merged, JSON_THROW_ON_ERROR))->not->toContain('guest-merge-token', 'guest_token_hash', 'password');
    expect(app(ReadCart::class)->snapshot($guest)['lines'])->toBe([]);
    expect(DB::table('carts')->where('guest_token_hash', hash('sha256', 'guest-merge-token'))->exists())->toBeFalse();
    expect($guestCart['revision'])->toBeGreaterThan(1)
        ->and($merged['revision'])->toBeGreaterThan(2)
        ->and(app(ReadCart::class)->snapshot($account)['revision'])->toBe($merged['revision']);
});

it('CheckoutOrdersCartMerge assigns a guest-only cart, moves unique lines, and ignores empty guests', function (): void {
    $catalog = mergeCatalog();
    $guest = CartOwner::guest('guest-only-token');
    $customer = cartCustomer();
    $account = CartOwner::customer($customer->id);
    expect(fn () => app(MergeCart::class)->merge($account, $guest))->toThrow(InvalidArgumentException::class);
    expect(app(MergeCart::class)->merge($guest, $account)['lines'])->toBe([]);

    $assigned = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 2,
        'expected_revision' => 1,
    ]);
    $converted = app(MergeCart::class)->merge($guest, $account);
    expect($converted['lines'])->toHaveCount(1)
        ->and($converted['lines'][0]['quantity'])->toBe(2)
        ->and($converted['notices'])->not->toContain('Some quantities were adjusted to the allowed maximum.')
        ->and($converted['revision'])->toBe($assigned['revision'] + 1);
    expect(app(ReadCart::class)->snapshot($account)['lines'][0]['variant_id'])->toBe($catalog['physical_variant']);

    $secondGuest = CartOwner::guest('unique-line-token');
    app(UpdateCart::class)->replaceLine($secondGuest, [
        'variant_id' => $catalog['digital_variant'],
        'quantity' => 1,
        'expected_revision' => 1,
    ]);
    $moved = app(MergeCart::class)->merge($secondGuest, $account);
    expect($moved['lines'])->toHaveCount(2)
        ->and(collect($moved['lines'])->firstWhere('variant_id', $catalog['digital_variant'])['quantity'])->toBe(1)
        ->and($moved['notices'])->not->toContain('Some quantities were adjusted to the allowed maximum.');

    $emptyGuest = CartOwner::guest('empty-guest-token');
    $now = now();
    DB::table('carts')->insert([
        'id' => (string) Str::uuid(),
        'customer_id' => null,
        'guest_token_hash' => $emptyGuest->guestTokenHash,
        'revision' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    expect(app(MergeCart::class)->merge($emptyGuest, $account)['notices'])->toBe([]);
    expect(DB::table('carts')->where('guest_token_hash', $emptyGuest->guestTokenHash)->exists())->toBeFalse();
});

it('CheckoutOrdersCartMerge login transfers the current guest cart and logout hides the account cart', function (): void {
    $catalog = mergeCatalog();
    $this->get('/')->assertOk();
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['physical_variant'],
        'quantity' => 4,
        'expectedRevision' => 1,
        'priceAmount' => 1,
    ])->assertOk();
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['digital_variant'],
        'quantity' => 1,
        'expectedRevision' => 2,
    ])->assertOk();

    $alice = cartCustomer();
    $bob = cartCustomer(['email' => 'bob@example.test']);
    app(UpdateCart::class)->replaceLine(CartOwner::customer($alice->id), [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 3,
        'expected_revision' => 1,
    ]);
    app(UpdateCart::class)->replaceLine(CartOwner::customer($alice->id), [
        'variant_id' => $catalog['digital_variant'],
        'quantity' => 1,
        'expected_revision' => 2,
    ]);
    app(UpdateCart::class)->replaceLine(CartOwner::customer($bob->id), [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 1,
        'expected_revision' => 1,
    ]);

    $this->from('/account/login')->post('/account/login', [
        'email' => 'unknown@example.test',
        'password' => 'Incorrect fictional passphrase',
    ])->assertRedirect('/account/login')->assertSessionHasErrors('email');
    $stillGuest = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    expect(collect($stillGuest['lines'])->firstWhere('variantId', $catalog['physical_variant'])['quantity'])->toBe(4);

    $this->post('/account/login', [
        'email' => CustomerRegistration::input()['email'],
        'password' => CustomerRegistration::input()['password'],
    ])->assertRedirect('/account');
    $cart = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    expect(collect($cart['lines'])->firstWhere('variantId', $catalog['physical_variant'])['quantity'])->toBe(5)
        ->and(collect($cart['lines'])->firstWhere('variantId', $catalog['digital_variant'])['quantity'])->toBe(1)
        ->and($cart['notices'])->toContain('Some quantities were adjusted to the allowed maximum.');
    $this->get('/cart')->assertOk()
        ->assertSee('Some quantities were adjusted to the allowed maximum.')
        ->assertSee('id="cart-lines"', false)
        ->assertSee('id="cart-indicator"', false)
        ->assertSee('Cart (')
        ->assertDontSee('Buy now')
        ->assertSee('href="/checkout"', false);
    $this->get('/products/studio-lamp')->assertOk()->assertSee('id="cart-indicator"', false)->assertSee('Cart (');
    expect(DB::table('carts')->where('customer_id', $bob->id)->exists())->toBeTrue();
    expect((int) DB::table('cart_lines')->where('cart_id', DB::table('carts')->where('customer_id', $bob->id)->value('id'))->value('quantity'))->toBe(1);

    $this->post('/account/logout')->assertRedirect('/account/login');
    $this->getJson('/store-api/v1/cart')->assertOk()->assertJsonPath('data.lines', []);
    $this->get('/cart')->assertOk()->assertSee('Your cart is empty.')->assertDontSee('Studio Lamp');

    $this->actingAs($catalog['owner'], 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/products')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/products', ['product' => ['title' => 'Denied']])->assertForbidden();
});
