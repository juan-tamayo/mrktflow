<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\ConsumeEvent;
use App\Domain\Storefront\Actions\ComposePage;
use App\Domain\Storefront\Data\ComposedPage;
use App\Domain\Storefront\Exceptions\SlotCompositionRefused;
use App\Domain\Storefront\Support\SystemSlots;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\View\ViewException;

beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-16T18:00:00Z')->utc());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{owner: StaffAccount, product_id: string, variant_id: string}
 */
function themePublicCatalog(): array
{
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $category = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $product = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published',
        'category_ids' => [$category['id']],
    ]));

    return [
        'owner' => $owner,
        'product_id' => $product['id'],
        'variant_id' => (string) DB::table('product_variants')->where('product_id', $product['id'])->value('id'),
    ];
}

/**
 * @return array{owner: StaffAccount, physical_variant: string}
 */
function themeQuoteCatalog(): array
{
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['amount' => 300])]])],
    ]);
    pricingSavedTaxRules($owner);
    $image = catalogReadyImage($owner->id);
    $product = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'price_amount' => 1000,
    ]));

    return [
        'owner' => $owner,
        'physical_variant' => (string) DB::table('product_variants')->where('product_id', $product['id'])->value('id'),
    ];
}

/**
 * @return array{owner: StaffAccount, digital_variant: string}
 */
function themeDigitalCatalog(): array
{
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['amount' => 300])]])],
    ]);
    pricingSavedTaxRules($owner);
    pricingSavedCoupon($owner, [
        'code' => 'FREE100',
        'percent_numerator' => 100,
        'percent_denominator' => 100,
        'max_redemptions' => 25,
        'max_redemptions_per_customer' => null,
    ]);
    $image = catalogReadyImage($owner->id);
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

    return ['owner' => $owner, 'digital_variant' => $digitalVariant];
}

function themeCustomer(string $email): CustomerAccount
{
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => $email,
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true, 'session_version' => 1, 'email_verified_at' => now(),
    ])->save();

    return $customer;
}

it('StorefrontTheme defines the slot contract for every themed page', function (): void {
    $compose = app(ComposePage::class);
    expect(SystemSlots::pages())->not->toBeEmpty();
    foreach (SystemSlots::pages() as $page) {
        $composed = $compose->compose($page);
        expect($composed)->toBeInstanceOf(ComposedPage::class)
            ->and($composed->page)->toBe($page)
            ->and($composed->slot('head')['kind'])->toBe('theme')
            ->and($composed->slot('announcement')['kind'])->toBe('theme')
            ->and($composed->slot('content')['kind'])->toBe('theme')
            ->and($composed->slot('masthead')['kind'])->toBe('platform')
            ->and($composed->slot('footer')['kind'])->toBe('platform')
            ->and(View::exists($composed->template))->toBeTrue();
        foreach ($composed->slots as $slot) {
            if ($slot['kind'] === 'platform' && $slot['region'] === 'main') {
                // Main-region platform components resolve from the platform's
                // own view location, never from a theme namespace a theme
                // directory could shadow.
                expect($slot['view'])->toStartWith('storefront.slots.')
                    ->and(View::exists((string) $slot['view']))->toBeTrue();
            }
        }
    }
});

it('StorefrontTheme reserves the commerce slots to the platform on the pages that act', function (): void {
    $compose = app(ComposePage::class);
    expect($compose->compose('checkout')->slot('checkout.summary')['view'])->toBe('storefront.slots.checkout-summary')
        ->and($compose->compose('checkout')->slot('checkout.actions')['view'])->toBe('storefront.slots.checkout-actions')
        ->and($compose->compose('product')->slot('product.purchase')['view'])->toBe('storefront.slots.product-purchase')
        ->and($compose->compose('cart')->slot('cart.line-actions')['view'])->toBe('storefront.slots.cart-line-actions')
        ->and($compose->compose('account-downloads')->slot('downloads.issue')['view'])->toBe('storefront.slots.download-issue')
        ->and($compose->compose('download-grant')->slot('download.start')['view'])->toBe('storefront.slots.download-start');
});

it('StorefrontTheme refuses theme content for platform slots and unknown slots', function (): void {
    $compose = app(ComposePage::class);
    expect(fn () => $compose->compose('checkout', ['checkout.actions' => 'default-theme::partials.footer']))
        ->toThrow(SlotCompositionRefused::class);
    expect(fn () => $compose->compose('home', ['masthead' => 'default-theme::partials.footer']))
        ->toThrow(SlotCompositionRefused::class);
    expect(fn () => $compose->compose('home', ['sidebar' => 'default-theme::partials.footer']))
        ->toThrow(SlotCompositionRefused::class);
    expect(fn () => $compose->compose('not-a-page'))->toThrow(InvalidArgumentException::class);
    $composed = $compose->compose('home', ['announcement' => 'default-theme::partials.footer']);
    expect($composed->slot('announcement')['view'])->toBe('default-theme::partials.footer')
        ->and($composed->slot('announcement')['kind'])->toBe('theme');
});

it('StorefrontTheme refuses a protected slot assignment at render time', function (): void {
    // Blade wraps exceptions raised while a view renders in a ViewException;
    // the refusal must be its cause.
    $thrown = null;
    try {
        Blade::render(
            "@extends('default-theme::layout', ['pageKey' => 'checkout', 'pageTitle' => 'T', 'slotViews' => ['checkout.actions' => 'default-theme::partials.footer']])@section('content')<p>x</p>@endsection"
        );
    } catch (Throwable $exception) {
        $thrown = $exception;
    }
    expect($thrown)->toBeInstanceOf(ViewException::class)
        ->and($thrown?->getPrevious())->toBeInstanceOf(SlotCompositionRefused::class);
});

it('StorefrontTheme renders every public route type through the one layout', function (): void {
    themePublicCatalog();
    foreach (['/', '/categories/lighting', '/products/studio-lamp', '/search', '/compare', '/wishlist'] as $url) {
        $this->get($url)->assertOk()
            ->assertSee('data-layout="storefront"', false)
            ->assertSee('data-platform-slot="masthead"', false)
            ->assertSee('data-platform-slot="footer"', false);
    }
    $this->get('/')->assertSee('data-page="home"', false);
    $this->get('/categories/lighting')->assertSee('data-page="category"', false);
    $this->get('/products/studio-lamp')->assertSee('data-page="product"', false);
    $this->get('/search')->assertSee('data-page="search"', false);
    $this->get('/compare')->assertSee('data-page="compare"', false);
    $this->get('/wishlist')->assertSee('data-page="wishlist"', false);
});

it('StorefrontTheme serves meaningful public HTML without JavaScript', function (): void {
    themePublicCatalog();
    // The raw server response carries the product, its server-owned price and
    // a working no-JS add-to-cart form; the Vue islands only enhance it.
    $this->get('/products/studio-lamp')->assertOk()
        ->assertSee('Studio Lamp')
        ->assertSee('USD 24.99')
        ->assertSee('data-platform-slot="product.purchase"', false)
        ->assertSee('<form method="post" action="/cart/lines"', false);
    $this->get('/categories/lighting')->assertOk()
        ->assertSee('Studio Lamp')
        ->assertSee('USD 24.99')
        ->assertSee('<a class="theme-product-card" href="/products/studio-lamp">', false);
    $this->get('/')->assertOk()
        ->assertSee('Studio Lamp')
        ->assertSee('USD 24.99');
});

it('StorefrontTheme renders cart line actions from the platform component', function (): void {
    $catalog = themePublicCatalog();
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['variant_id'], 'quantity' => 1, 'expectedRevision' => 1,
    ])->assertOk();
    $cart = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    $lineId = $cart['lines'][0]['id'];
    $this->get('/cart')->assertOk()
        ->assertSee('data-page="cart"', false)
        ->assertSee('data-platform-slot="cart.line-actions"', false)
        ->assertSee('action="/cart/lines/'.$lineId.'"', false)
        ->assertSee('action="/cart/lines/'.$lineId.'/delete"', false);
});

it('StorefrontTheme renders checkout summary and actions slots inside the quote flow', function (): void {
    $catalog = themeQuoteCatalog();
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['physical_variant'], 'quantity' => 1, 'expectedRevision' => 1,
    ])->assertOk();
    $cart = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    $created = $this->postJson('/store-api/v1/checkout-quotes', [
        'expectedRevision' => $cart['revision'],
        'email' => 'guest@example.test',
        'billing' => checkoutAddress(),
        'shipping' => checkoutAddress(),
    ])->assertOk();
    $quoteId = $created->json('data.id');
    $fingerprint = $created->json('data.fingerprint');
    $this->get('/checkout/'.$quoteId)->assertOk()
        ->assertSee('data-layout="storefront"', false)
        ->assertSee('data-page="checkout"', false)
        ->assertSee('data-platform-slot="checkout.summary"', false)
        ->assertSee('data-platform-slot="checkout.actions"', false)
        ->assertSee('USD 14.30')
        ->assertSee('action="/checkout/'.$quoteId.'/accept"', false)
        ->assertDontSee('action="/checkout/'.$quoteId.'/pay"', false);
    // Accepting through the platform-rendered form swaps the same actions
    // slot to the payment handoff; nothing theme-side re-authors it.
    $this->post('/checkout/'.$quoteId.'/accept', [
        'fingerprint' => $fingerprint, 'policy_acknowledged' => '1',
    ])->assertRedirect('/checkout/'.$quoteId);
    $this->get('/checkout/'.$quoteId)->assertOk()
        ->assertSee('data-platform-slot="checkout.actions"', false)
        ->assertSee('action="/checkout/'.$quoteId.'/pay"', false);
});

it('StorefrontTheme renders the platform checkout actions even when theme-side slot files exist', function (): void {
    $catalog = themeQuoteCatalog();
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['physical_variant'], 'quantity' => 1, 'expectedRevision' => 1,
    ])->assertOk();
    $cart = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    $created = $this->postJson('/store-api/v1/checkout-quotes', [
        'expectedRevision' => $cart['revision'],
        'email' => 'guest@example.test',
        'billing' => checkoutAddress(),
        'shipping' => checkoutAddress(),
    ])->assertOk();
    $quoteId = $created->json('data.id');
    // A theme author planting files at the paths a slot override would need
    // cannot shadow the platform components: they resolve from the platform
    // view location, not from the theme namespace.
    $hostile = [
        base_path('themes/default/views/slots/checkout-actions.blade.php'),
        base_path('themes/default/views/storefront/slots/checkout-actions.blade.php'),
    ];
    foreach ($hostile as $path) {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '<p>HOSTILE SLOT OVERRIDE</p>');
    }
    try {
        $this->get('/checkout/'.$quoteId)->assertOk()
            ->assertSee('data-platform-slot="checkout.actions"', false)
            ->assertSee('action="/checkout/'.$quoteId.'/accept"', false)
            ->assertDontSee('HOSTILE SLOT OVERRIDE');
    } finally {
        foreach ($hostile as $path) {
            File::delete($path);
        }
        File::deleteDirectory(base_path('themes/default/views/slots'));
        File::deleteDirectory(base_path('themes/default/views/storefront'));
    }
});

it('StorefrontTheme renders account routes through the layout and keeps system pages system-owned', function (): void {
    catalogInstalledOwner();
    // The sign-in surface stays a platform system view, not a themed page
    // (checked as a guest: signed-in customers are bounced off it).
    $this->get('/account/login')->assertOk()
        ->assertDontSee('data-layout="storefront"', false);
    $customer = themeCustomer('theme-account@example.test');
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/orders')->assertOk()
        ->assertSee('data-layout="storefront"', false)
        ->assertSee('data-page="account-orders"', false);
    $this->get('/account/downloads')->assertOk()
        ->assertSee('data-page="account-downloads"', false);
    $this->get('/account/preferences')->assertOk()
        ->assertSee('data-page="preferences"', false);
});

it('StorefrontTheme renders a paid order, its downloads and the grant page through the layout', function (): void {
    $catalog = themeDigitalCatalog();
    $customer = themeCustomer('theme-buyer@example.test');
    $owner = CartOwner::customer($customer->id);
    $cart = app(UpdateCart::class)->replaceLine($owner, [
        'variant_id' => $catalog['digital_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($owner, checkoutQuoteInput($cart['revision'], [
        'email' => $customer->email, 'coupon' => 'FREE100', 'shipping' => null,
    ]));
    app(AcceptQuote::class)->accept($owner, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($owner, $quote['id'], (string) Str::uuid());
    $orderId = $intent['order_id'];
    $paidEvent = DB::table('outbox_events')->where('event_type', 'checkout.order.paid')
        ->where('intent_key', 'order:paid:'.$orderId)->first();
    expect($paidEvent)->not->toBeNull();
    app(ConsumeEvent::class)->consume((string) $paidEvent->id, 'fulfillment.entitlements');
    $entitlementId = (string) DB::table('entitlements')->where('order_id', $orderId)->value('id');
    expect($entitlementId)->not->toBe('');

    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/orders/'.$orderId)->assertOk()
        ->assertSee('data-layout="storefront"', false)
        ->assertSee('data-page="account-order"', false);
    $this->get('/account/downloads')->assertOk()
        ->assertSee('data-page="account-downloads"', false)
        ->assertSee('data-platform-slot="downloads.issue"', false)
        ->assertSee('action="/account/downloads/'.$entitlementId.'/grants"', false);
    $this->post('/account/downloads/'.$entitlementId.'/grants')->assertRedirect();
    $grantId = (string) DB::table('download_grants')->where('entitlement_id', $entitlementId)->value('id');
    $this->get('/account/download-grants/'.$grantId)->assertOk()
        ->assertSee('data-page="download-grant"', false)
        ->assertSee('data-platform-slot="download.start"', false)
        ->assertSee('action="/account/download-grants/'.$grantId.'/start"', false);
});

it('StorefrontTheme renders order and payment status through the layout', function (): void {
    $catalog = themeDigitalCatalog();
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['digital_variant'], 'quantity' => 1, 'expectedRevision' => 1,
    ])->assertOk();
    $cart = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    $quote = $this->postJson('/store-api/v1/checkout-quotes', [
        'expectedRevision' => $cart['revision'],
        'email' => 'guest@example.test',
        'coupon' => 'FREE100',
        'billing' => checkoutAddress(),
        'shipping' => null,
    ])->assertOk()->json('data');
    $this->postJson('/store-api/v1/checkout-quotes/'.$quote['id'].'/accept', [
        'fingerprint' => $quote['fingerprint'], 'policyAcknowledged' => true,
    ])->assertOk();
    $session = $this->postJson('/store-api/v1/checkout-sessions', [
        'quoteId' => $quote['id'], 'fingerprint' => $quote['fingerprint'],
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');
    $this->get('/checkout/orders/'.$session['orderId'])->assertOk()
        ->assertSee('data-layout="storefront"', false)
        ->assertSee('data-page="order-status"', false)
        ->assertSee('Order confirmed');
    $this->get('/checkout/payment/'.$session['id'].'/return?session_id=cs_forged')->assertOk()
        ->assertSee('data-layout="storefront"', false)
        ->assertSee('data-page="payment-status"', false);
});
