<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\ArchiveProduct;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Exceptions\CartConflict;
use App\Domain\CheckoutOrders\Exceptions\CheckoutQuoteStale;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Pricing\Exceptions\PricingCouponRejected;
use App\Domain\Pricing\Exceptions\PricingTaxCoverageMissing;
use App\Domain\Shipping\Exceptions\ShippingNoService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\CustomerRegistration;

uses(DatabaseMigrations::class);
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-13T18:00:00Z')->utc());
});
afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{owner: StaffAccount, physical: string, digital: string, physical_variant: string, digital_variant: string}
 */
function quoteCatalog(): array
{
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['amount' => 300])]])],
    ]);
    pricingSavedTaxRules($owner);
    $image = catalogReadyImage($owner->id);
    $two = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'price_amount' => 1000,
    ]));
    $one = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'title' => 'Clamp', 'slug' => 'studio-clamp', 'sku' => 'CLAMP-001', 'price_amount' => 500,
    ]));
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
        'physical' => $two['id'],
        'digital' => $digital['id'],
        'physical_variant' => (string) DB::table('product_variants')->where('product_id', $two['id'])->value('id'),
        'second_variant' => (string) DB::table('product_variants')->where('product_id', $one['id'])->value('id'),
        'digital_variant' => $digitalVariant,
    ];
}

it('CheckoutOrdersQuote creates an expiring itemized quote from contact and destinations', function (): void {
    $catalog = quoteCatalog();
    pricingSavedCoupon($catalog['owner'], [
        'code' => 'SAVE10', 'max_redemptions' => 25, 'max_redemptions_per_customer' => 5,
    ]);
    $guest = CartOwner::guest('guest-quote-token');
    $first = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => 2, 'expected_revision' => 1,
    ]);
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['second_variant'], 'quantity' => 1, 'expected_revision' => $first['revision'],
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], ['coupon' => 'save10']));
    expect($quote['merchandise']['amount'])->toBe(2500)
        ->and($quote['discount']['amount'])->toBe(250)
        ->and($quote['shipping']['amount'])->toBe(300)
        ->and($quote['tax']['amount'])->toBe(255)
        ->and($quote['grand_total']['amount'])->toBe(2805)
        ->and($quote['price_mode'])->toBe('exclusive')
        ->and($quote['expires_at'])->toBe('2026-10-13T18:15:00Z')
        ->and($quote['fingerprint'])->toHaveLength(64)
        ->and($quote['accepted_at'])->toBeNull()
        ->and($quote['email'])->toBe('guest@example.test');
    expect(json_encode($quote, JSON_THROW_ON_ERROR))->not->toContain('guest-quote-token', 'guest_token_hash', 'password');
    $accepted = app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true);
    expect($accepted['accepted_at'])->not->toBeNull()
        ->and($accepted['fingerprint'])->toBe($quote['fingerprint']);
});

it('CheckoutOrdersQuote invalidates acceptance when the cart rules or destination change', function (): void {
    $catalog = quoteCatalog();
    $guest = CartOwner::guest('guest-quote-token');
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision']));
    $corrected = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], [
        'shipping' => checkoutAddress(['postal_code' => '94107', 'city' => 'San Francisco']),
    ]));
    expect($corrected['id'])->not->toBe($quote['id'])
        ->and($corrected['fingerprint'])->not->toBe($quote['fingerprint']);
    expect(fn () => app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true))
        ->toThrow(CheckoutQuoteStale::class);
    expect(fn () => app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'] - 1)))
        ->toThrow(CartConflict::class);

    $fresh = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision']));
    app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => 2, 'expected_revision' => $cart['revision'],
    ]);
    expect(fn () => app(AcceptQuote::class)->accept($guest, $fresh['id'], $fresh['fingerprint'], true))
        ->toThrow(CheckoutQuoteStale::class);

    $afterCart = app(CreateQuote::class)->create($guest, checkoutQuoteInput((int) DB::table('carts')->value('revision')));
    DB::table('product_variants')->where('id', $catalog['physical_variant'])->update(['price_amount' => 1111]);
    expect(fn () => app(AcceptQuote::class)->accept($guest, $afterCart['id'], $afterCart['fingerprint'], true))
        ->toThrow(CheckoutQuoteStale::class);

    $priced = app(CreateQuote::class)->create($guest, checkoutQuoteInput((int) DB::table('carts')->value('revision')));
    pricingSavedTaxRules($catalog['owner'], [
        'version' => (int) DB::table('pricing_settings')->value('revision'),
        'rules' => [pricingTaxRuleInput(['rate_numerator' => 5])],
    ]);
    expect(fn () => app(AcceptQuote::class)->accept($guest, $priced['id'], $priced['fingerprint'], true))
        ->toThrow(CheckoutQuoteStale::class);

    $taxed = app(CreateQuote::class)->create($guest, checkoutQuoteInput((int) DB::table('carts')->value('revision')));
    shippingSavedRules($catalog['owner'], [
        'origin' => shippingOriginInput(['version' => (int) DB::table('shipping_origins')->value('revision')]),
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['amount' => 900])]])],
    ]);
    expect(fn () => app(AcceptQuote::class)->accept($guest, $taxed['id'], $taxed['fingerprint'], true))
        ->toThrow(CheckoutQuoteStale::class);

    Carbon::setTestNow(Carbon::parse('2026-10-13T18:16:00Z')->utc());
    $expired = app(CreateQuote::class)->create($guest, checkoutQuoteInput((int) DB::table('carts')->value('revision')));
    Carbon::setTestNow(Carbon::parse('2026-10-13T18:32:00Z')->utc());
    expect(fn () => app(AcceptQuote::class)->accept($guest, $expired['id'], $expired['fingerprint'], true))
        ->toThrow(CheckoutQuoteStale::class);
});

it('CheckoutOrdersQuote refuses empty unavailable digital-over-one missing coverage and foreign carts', function (): void {
    $catalog = quoteCatalog();
    $guest = CartOwner::guest('guest-quote-token');
    expect(fn () => app(CreateQuote::class)->create($guest, checkoutQuoteInput(1)))
        ->toThrow(ValidationException::class);
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    expect(fn () => app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], [
        'shipping' => checkoutAddress(['country_code' => 'CA', 'region' => 'ON', 'postal_code' => 'M5V2T6', 'city' => 'Toronto']),
    ])))->toThrow(ShippingNoService::class);
    expect(fn () => app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], [
        'coupon' => 'MISSING',
    ])))->toThrow(PricingCouponRejected::class);
    expect(fn () => app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], [
        'email' => 'not-an-email',
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], [
        'shipping' => null,
    ])))->toThrow(ValidationException::class);

    $digital = CartOwner::guest('digital-quote-token');
    $digitalCart = app(UpdateCart::class)->replaceLine($digital, [
        'variant_id' => $catalog['digital_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $digitalQuote = app(CreateQuote::class)->create($digital, checkoutQuoteInput($digitalCart['revision'], [
        'shipping' => null,
    ]));
    expect($digitalQuote['shipping']['amount'])->toBe(0)
        ->and($digitalQuote['requires_delivery'])->toBeFalse();
    expect(fn () => app(AcceptQuote::class)->accept($guest, $digitalQuote['id'], $digitalQuote['fingerprint'], true))
        ->toThrow(CheckoutQuoteStale::class);

    DB::table('tax_rules')->delete();
    expect(fn () => app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'])))
        ->toThrow(PricingTaxCoverageMissing::class);

    app(ArchiveProduct::class)->archive($catalog['owner'], [
        'id' => $catalog['physical'],
        'version' => (int) DB::table('products')->where('id', $catalog['physical'])->value('version'),
    ]);
    expect(fn () => app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'])))
        ->toThrow(ValidationException::class);
});

it('CheckoutOrdersQuote HTTP quotes guests and accounts, corrects addresses, and rejects stale CSRF posts', function (): void {
    $catalog = quoteCatalog();
    $this->get('/')->assertStatus(503)->assertDontSee('Checkout');
    $this->get('/checkout')->assertRedirect('/cart');
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['physical_variant'], 'quantity' => 1, 'expectedRevision' => 1,
    ])->assertOk();
    $cart = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    $this->get('/cart')->assertOk()->assertSee('Checkout')->assertDontSee('Checkout is not available yet');
    $this->get('/checkout')->assertOk()
        ->assertSee('Contact')
        ->assertSee('name="email"', false)
        ->assertSee('Billing')
        ->assertSee('I acknowledge the store policies')
        ->assertSee('Payment is not available yet')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $created = $this->postJson('/store-api/v1/checkout-quotes', [
        'expectedRevision' => $cart['revision'],
        'email' => 'guest@example.test',
        'billing' => checkoutAddress(),
        'shipping' => checkoutAddress(),
        'priceAmount' => 1,
    ])->assertOk();
    expect($created->json('data.grandTotal.amount'))->toBe(1430)
        ->and($created->json('data.tax.amount'))->toBe(130)
        ->and($created->headers->get('Cache-Control'))->toContain('no-store');
    $quoteId = $created->json('data.id');
    $fingerprint = $created->json('data.fingerprint');
    $this->get('/checkout/'.$quoteId)->assertOk()
        ->assertSee('USD 14.30')
        ->assertSee('name="shipping[postal_code]"', false);
    $this->from('/checkout/'.$quoteId)->post('/checkout', [
        'expected_revision' => $cart['revision'],
        'email' => 'guest@example.test',
        'billing' => checkoutAddress(),
        'shipping' => checkoutAddress(['postal_code' => '94107', 'city' => 'San Francisco']),
    ])->assertRedirect();
    $this->postJson('/store-api/v1/checkout-quotes/'.$quoteId.'/accept', [
        'fingerprint' => $fingerprint,
        'policyAcknowledged' => true,
    ])->assertStatus(409);
    expect($this->postJson('/store-api/v1/checkout-quotes/'.$quoteId.'/accept', [
        'fingerprint' => $fingerprint,
        'policyAcknowledged' => true,
    ])->json('error.code'))->toBe('STALE');

    $current = $this->postJson('/store-api/v1/checkout-quotes', [
        'expectedRevision' => $cart['revision'],
        'email' => 'guest@example.test',
        'billing' => checkoutAddress(),
        'shipping' => checkoutAddress(['postal_code' => '94107', 'city' => 'San Francisco']),
    ])->assertOk()->json('data');
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['physical_variant'], 'quantity' => 2, 'expectedRevision' => $cart['revision'],
    ])->assertOk();
    $this->postJson('/store-api/v1/checkout-quotes/'.$current['id'].'/accept', [
        'fingerprint' => $current['fingerprint'],
        'policyAcknowledged' => true,
    ])->assertStatus(409);

    $alice = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $this->actingAs($alice, 'customer')->withSession(['customer_session_version' => 1]);
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['digital_variant'], 'quantity' => 1, 'expectedRevision' => 1,
    ])->assertOk();
    $accountQuote = $this->postJson('/store-api/v1/checkout-quotes', [
        'expectedRevision' => 2,
        'email' => $alice->email,
        'billing' => checkoutAddress(),
        'shipping' => null,
    ])->assertOk()->json('data');
    expect($accountQuote['requiresDelivery'])->toBeFalse()
        ->and($accountQuote['shipping']['amount'])->toBe(0);
    $this->postJson('/store-api/v1/checkout-quotes/'.$accountQuote['id'].'/accept', [
        'fingerprint' => $accountQuote['fingerprint'],
        'policyAcknowledged' => true,
    ])->assertOk()->assertJsonPath('data.acceptedAt', fn ($value): bool => is_string($value) && $value !== '');

    $this->flushSession();
    $this->postJson('/store-api/v1/checkout-quotes/'.$accountQuote['id'].'/accept', [
        'fingerprint' => $accountQuote['fingerprint'],
        'policyAcknowledged' => true,
    ])->assertStatus(409);

    $this->app->instance('env', 'local');
    $this->postJson('/store-api/v1/checkout-quotes', [
        'expectedRevision' => 2,
        'email' => 'guest@example.test',
        'billing' => checkoutAddress(),
        'shipping' => checkoutAddress(),
    ])->assertStatus(419);
    $this->app->instance('env', 'testing');
});

it('CheckoutOrdersQuote HTTP keeps privileged staff behind two-factor and leaves the storefront closed', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/products')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/products', ['product' => ['title' => 'Denied']])->assertForbidden();
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->postJson('/admin/taxes', ['rules' => pricingTaxRulesInput()])
        ->assertUnauthorized();
    $this->get('/')->assertStatus(503);
});
