<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\StartPayment;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Exceptions\CheckoutIntentConflict;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Http\Controllers\Storefront\PaymentController;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-13T18:00:00Z')->utc());
    config([
        'app.url' => 'http://127.0.0.1:8000',
        'services.stripe.secret' => 'sk_test_fixture',
        'services.stripe.account' => 'acct_fixture',
        'services.stripe.live' => false,
    ]);
});
afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{owner: StaffAccount, physical_variant: string, digital_variant: string}
 */
function paymentCatalog(int $onHand = 4): array
{
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['amount' => 300])]])],
    ]);
    pricingSavedTaxRules($owner);
    pricingSavedCoupon($owner, [
        'code' => 'SAVE10',
        'max_redemptions' => 25,
        'max_redemptions_per_customer' => null,
    ]);
    $image = catalogReadyImage($owner->id);
    $physical = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'price_amount' => 2500,
    ]));
    $physicalVariant = (string) DB::table('product_variants')->where('product_id', $physical['id'])->value('id');
    app(AdjustStock::class)->adjust($owner, [
        'variant_id' => $physicalVariant,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => $onHand,
        'low_stock_threshold' => 1,
    ]);
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
        'physical_variant' => $physicalVariant,
        'digital_variant' => $digitalVariant,
    ];
}

function fakeOpenCheckoutSession(int $amount, string $attemptId, string $sessionId = 'cs_test_hosted1'): void
{
    Http::fake(function (Request $request) use ($amount, $attemptId, $sessionId) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/checkout/sessions')) {
            return Http::response([
                'id' => $sessionId,
                'object' => 'checkout.session',
                'amount_total' => $amount,
                'currency' => 'usd',
                'client_reference_id' => $attemptId,
                'status' => 'open',
                'payment_status' => 'unpaid',
                'expires_at' => Carbon::parse('2026-10-13T18:30:00Z')->utc()->getTimestamp(),
                'url' => 'https://checkout.stripe.com/c/pay/'.$sessionId,
                'payment_intent' => null,
                'livemode' => false,
                'payment_method_types' => ['card'],
            ], 200);
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });
}

it('CheckoutOrdersGateway starts one hosted session outside locks and replays the same attempt', function (): void {
    $catalog = paymentCatalog();
    $guest = CartOwner::guest('guest-payment-token');
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], ['coupon' => 'SAVE10']));
    app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], (string) Str::uuid());
    expect($intent['attempt_status'])->toBe('created')->and($intent['grand_total']['amount'])->toBe(2805);
    fakeOpenCheckoutSession(2805, $intent['id']);
    $first = app(StartPayment::class)->start($guest, $intent['id']);
    $replay = app(StartPayment::class)->start($guest, $intent['id']);
    expect($first['attempt_status'])->toBe('awaiting_payment')
        ->and($first['provider_redirect'])->toBe('https://checkout.stripe.com/c/pay/cs_test_hosted1')
        ->and($replay['provider_redirect'])->toBe($first['provider_redirect'])
        ->and($replay['id'])->toBe($first['id']);
    Http::assertSentCount(1);
    expect(DB::table('payment_attempts')->where('id', $intent['id'])->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('payment_attempts')->where('id', $intent['id'])->value('provider'))->toBe('stripe')
        ->and(DB::table('payment_attempts')->where('id', $intent['id'])->value('provider_session_id'))->toBe('cs_test_hosted1')
        ->and(DB::table('payment_attempts')->where('id', $intent['id'])->value('outbound_hash'))->not->toBeNull()
        ->and(DB::table('stock_reservations')->where('intent_id', $intent['order_id'])->where('status', 'active')->count())->toBe(1);
});

it('CheckoutOrdersGateway records unknown timeouts without releasing holds or marking the order paid', function (): void {
    $catalog = paymentCatalog();
    $guest = CartOwner::guest('guest-unknown-token');
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], ['coupon' => 'SAVE10']));
    app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], (string) Str::uuid());
    $calls = 0;
    Http::fake(function (Request $request) use ($intent, &$calls) {
        $calls++;
        if ($calls === 1) {
            throw new ConnectionException('timed out');
        }
        if ($request->method() === 'POST' && str_contains($request->url(), '/v1/checkout/sessions')) {
            $key = $request->header('Idempotency-Key');
            $expected = 'payment:'.$intent['id'];
            if ($key !== $expected && $key !== [$expected]) {
                return Http::response(['error' => ['message' => 'wrong idempotency key']], 400);
            }

            return Http::response([
                'id' => 'cs_test_recovered',
                'object' => 'checkout.session',
                'amount_total' => 2805,
                'currency' => 'usd',
                'client_reference_id' => $intent['id'],
                'status' => 'open',
                'payment_status' => 'unpaid',
                'expires_at' => Carbon::parse('2026-10-13T18:30:00Z')->utc()->getTimestamp(),
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_recovered',
                'payment_intent' => null,
                'livemode' => false,
                'payment_method_types' => ['card'],
            ], 200);
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });
    $unknown = app(StartPayment::class)->start($guest, $intent['id']);
    expect($unknown['attempt_status'])->toBe('unknown')
        ->and($unknown['order_status'])->toBe('pending')
        ->and($unknown['provider_redirect'])->toBeNull();
    expect(DB::table('payment_attempts')->where('id', $intent['id'])->value('status'))->toBe('unknown')
        ->and(DB::table('stock_reservations')->where('intent_id', $intent['order_id'])->where('status', 'active')->count())->toBe(1);
    $recovered = app(StartPayment::class)->start($guest, $intent['id']);
    expect($recovered['attempt_status'])->toBe('awaiting_payment')
        ->and($recovered['provider_redirect'])->toBe('https://checkout.stripe.com/c/pay/cs_test_recovered');
});

it('CheckoutOrdersGateway HTTP starts payment, ignores return URLs as capture evidence, and skips Stripe for zero totals', function (): void {
    $catalog = paymentCatalog();
    $this->get('/')->assertStatus(503);
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['physical_variant'], 'quantity' => 1, 'expectedRevision' => 1,
    ])->assertOk();
    $cart = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    $quote = $this->postJson('/store-api/v1/checkout-quotes', [
        'expectedRevision' => $cart['revision'],
        'email' => 'guest@example.test',
        'coupon' => 'SAVE10',
        'billing' => checkoutAddress(),
        'shipping' => checkoutAddress(),
    ])->assertOk()->json('data');
    $this->postJson('/store-api/v1/checkout-quotes/'.$quote['id'].'/accept', [
        'fingerprint' => $quote['fingerprint'],
        'policyAcknowledged' => true,
    ])->assertOk();
    $session = $this->postJson('/store-api/v1/checkout-sessions', [
        'quoteId' => $quote['id'],
        'fingerprint' => $quote['fingerprint'],
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');
    fakeOpenCheckoutSession(2805, $session['id']);
    $started = $this->postJson('/store-api/v1/checkout-sessions/'.$session['id'].'/payment')->assertOk();
    expect($started->json('data.attemptStatus'))->toBe('awaiting_payment')
        ->and($started->json('data.providerRedirect'))->toBe('https://checkout.stripe.com/c/pay/cs_test_hosted1')
        ->and($started->headers->get('Cache-Control'))->toContain('no-store');
    $this->get('/checkout/payment/'.$session['id'].'/return?session_id=cs_test_hosted1&payment_intent=pi_forged')
        ->assertOk()
        ->assertSee('Payment is still processing')
        ->assertDontSee('paid');
    expect(DB::table('payment_attempts')->where('id', $session['id'])->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('orders')->where('id', $session['orderId'])->value('status'))->toBe('pending');
    $this->get('/checkout/payment/'.$session['id'].'/cancel')->assertOk()->assertSee('Payment was not completed');
    $this->app->instance('env', 'local');
    $this->postJson('/store-api/v1/checkout-sessions/'.$session['id'].'/payment')->assertStatus(419);
    $this->app->instance('env', 'testing');
    pricingSavedCoupon($catalog['owner'], [
        'code' => 'FREE100',
        'percent_numerator' => 100,
        'percent_denominator' => 100,
        'max_redemptions' => 25,
        'max_redemptions_per_customer' => null,
    ]);
    $digitalOwner = CartOwner::guest('guest-zero-pay');
    $digitalCart = app(UpdateCart::class)->replaceLine($digitalOwner, [
        'variant_id' => $catalog['digital_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $zeroQuote = app(CreateQuote::class)->create($digitalOwner, checkoutQuoteInput($digitalCart['revision'], [
        'coupon' => 'FREE100',
        'shipping' => null,
    ]));
    app(AcceptQuote::class)->accept($digitalOwner, $zeroQuote['id'], $zeroQuote['fingerprint'], true);
    $zero = app(CreateCheckoutIntent::class)->create($digitalOwner, $zeroQuote['id'], (string) Str::uuid());
    Http::fake(fn () => throw new RuntimeException('stripe must not be called'));
    $local = app(StartPayment::class)->start($digitalOwner, $zero['id']);
    expect($local['zero_total'])->toBeTrue()
        ->and($local['attempt_status'])->toBe('succeeded')
        ->and($local['provider_redirect'])->toBeNull();
});

it('CheckoutOrdersGateway HTML pay redirects to the hosted session and refuses a terminal attempt', function (): void {
    $catalog = paymentCatalog();
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['physical_variant'], 'quantity' => 1, 'expectedRevision' => 1,
    ])->assertOk();
    $cart = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    $quote = $this->postJson('/store-api/v1/checkout-quotes', [
        'expectedRevision' => $cart['revision'],
        'email' => 'guest@example.test',
        'coupon' => 'SAVE10',
        'billing' => checkoutAddress(),
        'shipping' => checkoutAddress(),
    ])->assertOk()->json('data');
    $this->postJson('/store-api/v1/checkout-quotes/'.$quote['id'].'/accept', [
        'fingerprint' => $quote['fingerprint'],
        'policyAcknowledged' => true,
    ])->assertOk();
    Http::fake(function (Request $request) {
        if ($request->method() === 'POST' && str_contains($request->url(), '/v1/checkout/sessions')) {
            $attemptId = $request->data()['client_reference_id'] ?? '';

            return Http::response([
                'id' => 'cs_test_htmlpay',
                'object' => 'checkout.session',
                'amount_total' => 2805,
                'currency' => 'usd',
                'client_reference_id' => $attemptId,
                'status' => 'open',
                'payment_status' => 'unpaid',
                'expires_at' => Carbon::parse('2026-10-13T18:30:00Z')->utc()->getTimestamp(),
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_htmlpay',
                'payment_intent' => null,
                'livemode' => false,
                'payment_method_types' => ['card'],
            ], 200);
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });
    $this->from('/checkout/'.$quote['id'])->post('/checkout/'.$quote['id'].'/pay', [
        'fingerprint' => $quote['fingerprint'],
    ])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_htmlpay');
    $guest = CartOwner::guest('guest-failed-pay');
    $cartTwo = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quoteTwo = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cartTwo['revision'], ['coupon' => 'SAVE10']));
    app(AcceptQuote::class)->accept($guest, $quoteTwo['id'], $quoteTwo['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($guest, $quoteTwo['id'], (string) Str::uuid());
    DB::table('payment_attempts')->where('id', $intent['id'])->update(['status' => 'failed']);
    expect(fn () => app(StartPayment::class)->start($guest, $intent['id']))->toThrow(CheckoutIntentConflict::class);
    $this->get('/')->assertStatus(503);
});

it('CheckoutOrdersGateway HTTP keeps privileged staff behind two-factor and leaves the storefront closed', function (): void {
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
        ->postJson('/store-api/v1/checkout-sessions/'.(string) Str::uuid().'/payment')
        ->assertStatus(409);
    $this->get('/')->assertStatus(503);
    expect(class_exists(PaymentController::class))->toBeTrue();
});
