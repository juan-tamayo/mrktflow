<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\ArchiveProduct;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Pricing\Actions\PreviewPricing;
use App\Domain\Pricing\Exceptions\PricingInvalidQuote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

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
 * @return array{owner: StaffAccount, physical_variant: string, digital_variant: string}
 */
function pricingPreviewCatalog(): array
{
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['amount' => 300])]])],
    ]);
    pricingSavedTaxRules($owner);
    pricingSavedCoupon($owner, [
        'code' => 'SAVE10', 'max_redemptions' => 25, 'max_redemptions_per_customer' => 5,
    ]);
    $image = catalogReadyImage($owner->id);
    $physical = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'price_amount' => 1000,
    ]));
    $digital = app(SaveProduct::class)->save($owner, catalogDigitalProductInput($image, [
        'status' => 'published', 'price_amount' => 500,
    ]));
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
        'physical_variant' => (string) DB::table('product_variants')->where('product_id', $physical['id'])->value('id'),
        'digital_variant' => $digitalVariant,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pricingPreviewInput(array $overrides = []): array
{
    return array_replace([
        'cart' => 'mixed',
        'coupon' => 'SAVE10',
        'billing_country_code' => 'US',
        'billing_region_code' => 'CA',
        'billing_postal_code' => '90210',
        'shipping_country_code' => 'US',
        'shipping_region_code' => 'CA',
        'shipping_postal_code' => '90210',
        'physical_amount' => 2000,
        'digital_amount' => 500,
        'physical_grams' => 2400,
        'physical_class_key' => 'general',
        'digital_class_key' => 'general',
        'physical_tax_class_key' => 'standard',
        'digital_tax_class_key' => 'standard',
    ], $overrides);
}

it('PricingPreview matches storefront mixed-cart itemized totals including coupon shipping and tax', function (): void {
    $catalog = pricingPreviewCatalog();
    $guest = CartOwner::guest('guest-preview-token');
    $first = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => 2, 'expected_revision' => 1,
    ]);
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['digital_variant'], 'quantity' => 1, 'expected_revision' => $first['revision'],
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], ['coupon' => 'save10']));
    $preview = app(PreviewPricing::class)->preview(pricingPreviewInput());
    expect($preview['ok'])->toBeTrue()
        ->and($preview['reason'])->toBeNull()
        ->and($preview['cart'])->toBe('mixed')
        ->and($preview['price_mode'])->toBe('exclusive')
        ->and($preview['currency'])->toBe('USD')
        ->and($preview['exponent'])->toBe(2)
        ->and($preview['coupon'])->toBe('SAVE10')
        ->and($preview['merchandise']['amount'])->toBe($quote['merchandise']['amount'])
        ->and($preview['discount']['amount'])->toBe($quote['discount']['amount'])
        ->and($preview['shipping']['amount'])->toBe($quote['shipping']['amount'])
        ->and($preview['tax']['amount'])->toBe($quote['tax']['amount'])
        ->and($preview['grand_total']['amount'])->toBe($quote['grand_total']['amount'])
        ->and($preview['merchandise']['amount'])->toBe(2500)
        ->and($preview['discount']['amount'])->toBe(250)
        ->and($preview['shipping']['amount'])->toBe(300)
        ->and($preview['tax']['amount'])->toBe(255)
        ->and($preview['grand_total']['amount'])->toBe(2805)
        ->and($preview['merchandise']['formatted'])->toBe($quote['merchandise']['formatted'])
        ->and($preview['grand_total']['formatted'])->toBe('USD 28.05');
    expect(json_encode($preview, JSON_THROW_ON_ERROR))->not->toContain('guest-preview-token', 'password');
    $physical = app(PreviewPricing::class)->preview(pricingPreviewInput([
        'cart' => 'physical', 'digital_amount' => 0, 'physical_amount' => 2000, 'coupon' => null,
    ]));
    expect($physical['ok'])->toBeTrue()
        ->and($physical['merchandise']['amount'])->toBe(2000)
        ->and($physical['discount']['amount'])->toBe(0)
        ->and($physical['shipping']['amount'])->toBe(300);
    $digital = app(PreviewPricing::class)->preview(pricingPreviewInput([
        'cart' => 'digital', 'physical_amount' => 0, 'digital_amount' => 500, 'coupon' => null,
        'shipping_country_code' => null, 'shipping_region_code' => null, 'shipping_postal_code' => null,
    ]));
    expect($digital['ok'])->toBeTrue()
        ->and($digital['shipping']['amount'])->toBe(0)
        ->and($digital['tax']['amount'])->toBe(50)
        ->and($digital['grand_total']['amount'])->toBe(550);
    $this->get('/')->assertOk();
});

it('PricingPreview explains missing tax shipping coupon and currency failures without inventing totals', function (): void {
    $catalog = pricingPreviewCatalog();
    $missingTax = app(PreviewPricing::class)->preview(pricingPreviewInput([
        'billing_country_code' => 'FR', 'coupon' => null,
    ]));
    expect($missingTax['ok'])->toBeFalse()
        ->and($missingTax['reason'])->toContain('tax')
        ->and($missingTax['grand_total']['amount'])->toBe(0);
    $noShip = app(PreviewPricing::class)->preview(pricingPreviewInput([
        'shipping_country_code' => 'CA', 'shipping_region_code' => 'ON', 'shipping_postal_code' => 'M5V2T6',
        'coupon' => null,
    ]));
    expect($noShip['ok'])->toBeFalse()
        ->and($noShip['reason'])->toContain('shipping')
        ->and($noShip['grand_total']['amount'])->toBe(0);
    $badCoupon = app(PreviewPricing::class)->preview(pricingPreviewInput(['coupon' => 'NOPE']));
    expect($badCoupon['ok'])->toBeFalse()
        ->and($badCoupon['reason'])->toContain('coupon')
        ->and($badCoupon['grand_total']['amount'])->toBe(0);
    expect(fn () => app(PreviewPricing::class)->preview(pricingPreviewInput(['cart' => 'bundle'])))
        ->toThrow(PricingInvalidQuote::class);
    expect($catalog['owner']->hasPermission('pricing.manage'))->toBeTrue();
    $this->get('/')->assertOk();
});

it('PricingPreview HTTP keeps privileged staff behind two-factor and rejects customers', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/pricing-preview')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/pricing-preview', ['preview' => pricingPreviewInput()])->assertForbidden();
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->postJson('/admin/pricing-preview', ['preview' => pricingPreviewInput()])
        ->assertUnauthorized();
    $this->get('/')->assertOk();
});

it('PricingPreview HTTP lets authorized staff preview identical totals and denies other roles', function (): void {
    $catalog = pricingPreviewCatalog();
    $this->actingAs($catalog['owner'], 'staff')->withSession(catalogStaffSession($catalog['owner']));
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canManagePricing', true));
    $this->get('/admin/pricing-preview')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('PricingPreview')
        ->where('preview', null)
        ->where('canWrite', true)
        ->where('currency', 'USD')
        ->where('exponent', 2));
    $this->post('/admin/pricing-preview', ['preview' => pricingPreviewInput()])->assertRedirect('/admin/pricing-preview');
    $this->get('/admin/pricing-preview')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('PricingPreview')
        ->where('preview.ok', true)
        ->where('preview.merchandise.amount', 2500)
        ->where('preview.discount.amount', 250)
        ->where('preview.shipping.amount', 300)
        ->where('preview.tax.amount', 255)
        ->where('preview.grand_total.amount', 2805)
        ->where('preview.grand_total.formatted', 'USD 28.05'));
    $this->post('/admin/pricing-preview', ['preview' => pricingPreviewInput([
        'billing_country_code' => 'FR', 'coupon' => null,
    ])])->assertRedirect('/admin/pricing-preview');
    $this->get('/admin/pricing-preview')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('PricingPreview')
        ->where('preview.ok', false));
    foreach (['content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $catalog['owner']->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $this->actingAs($catalog['owner']->fresh(), 'staff')->withSession(catalogStaffSession($catalog['owner']->fresh()))
            ->postJson('/admin/pricing-preview', ['preview' => pricingPreviewInput()])
            ->assertForbidden();
    }
    $this->get('/')->assertOk();
});

it('PricingPreview HTTP rejects forged preview posts', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->app->instance('env', 'local');
    $this->postJson('/admin/pricing-preview', ['preview' => pricingPreviewInput()])->assertStatus(419);
    $this->app->instance('env', 'testing');
});

it('PricingPreview cart gate leaves empty and unavailable shoppers a way forward', function (): void {
    $catalog = pricingPreviewCatalog();
    $this->get('/cart')->assertOk()
        ->assertSee('Your cart is empty.')
        ->assertSee('href="/search"', false)
        ->assertDontSee('href="/checkout"', false);
    $this->from('/products/studio-lamp')->post('/cart/lines', [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 1,
        'expected_revision' => 1,
    ])->assertRedirect('/cart');
    $this->get('/cart')->assertOk()->assertSee('Checkout')->assertSee('href="/checkout"', false);
    $productId = (string) DB::table('products')->where('slug', 'studio-lamp')->value('id');
    app(ArchiveProduct::class)->archive($catalog['owner'], [
        'id' => $productId,
        'version' => (int) DB::table('products')->where('id', $productId)->value('version'),
    ]);
    $this->get('/cart')->assertOk()
        ->assertSee('An item is no longer available.')
        ->assertSee('Remove unavailable items to continue')
        ->assertDontSee('href="/checkout"', false);
    $this->get('/')->assertOk();
});
