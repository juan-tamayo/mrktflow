<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\CompleteFreeOrder;
use App\Domain\CheckoutOrders\Actions\ConfirmOrderPayment;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\PaymentSession;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\ConsumeEvent;
use App\Http\Controllers\Storefront\OrderStatusController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-16T00:00:00Z')->utc());
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
function freeCatalog(int $onHand = 4): array
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

it('CheckoutOrdersFree completes a zero-total order through the local confirmation pipeline without a gateway', function (): void {
    $catalog = freeCatalog();
    $guest = CartOwner::guest('guest-free-token');
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['digital_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], [
        'coupon' => 'FREE100',
        'shipping' => null,
    ]));
    expect($quote['grand_total']['amount'])->toBe(0);
    app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true);
    Http::fake(fn () => throw new RuntimeException('stripe must not be called'));
    $intent = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], (string) Str::uuid());
    expect($intent['zero_total'])->toBeTrue()
        ->and($intent['order_status'])->toBe('confirmed')
        ->and($intent['attempt_status'])->toBe('succeeded');
    $capture = 'local_'.$intent['id'];
    expect(DB::table('payment_attempts')->where('id', $intent['id'])->value('provider'))->toBe('local')
        ->and(DB::table('payments')->where('order_id', $intent['order_id'])->count())->toBe(1)
        ->and(DB::table('payments')->where('order_id', $intent['order_id'])->value('provider'))->toBe('local')
        ->and((int) DB::table('payments')->where('order_id', $intent['order_id'])->value('amount'))->toBe(0)
        ->and(DB::table('payments')->where('order_id', $intent['order_id'])->value('provider_capture_id'))->toBe($capture)
        ->and(DB::table('coupon_holds')->where('intent_id', $intent['order_id'])->value('status'))->toBe('committed')
        ->and(DB::table('outbox_events')->where('event_type', 'checkout.order.paid')->where('intent_key', 'order:paid:'.$intent['order_id'])->count())->toBe(1);
    $paidEvent = DB::table('outbox_events')->where('event_type', 'checkout.order.paid')->first();
    $payload = json_decode(Crypt::decryptString((string) $paidEvent->payload), true);
    expect($payload['amount'])->toBe(0)
        ->and($payload['capture_id'])->toBe($capture)
        ->and($payload['attempt_id'])->toBe($intent['id'])
        ->and($payload)->not->toHaveKey('email');
    app(CompleteFreeOrder::class)->complete($intent['id']);
    app(CompleteFreeOrder::class)->complete($intent['id']);
    expect(DB::table('payments')->count())->toBe(1)
        ->and(DB::table('outbox_events')->where('event_type', 'checkout.order.paid')->count())->toBe(1)
        ->and(Http::recorded())->toHaveCount(0);
    $forged = PaymentSession::observed(
        new PaymentIntent($intent['id'], 1, 'USD', 'acct_fixture', false),
        new PaymentIntent($intent['id'], 1, 'USD', 'acct_fixture', false),
        'cs_forged_free',
        'complete',
        'paid',
        1760572800,
        'pi_forged_free',
    );
    app(ConfirmOrderPayment::class)->confirm($intent['id'], $forged);
    expect(DB::table('payments')->where('order_id', $intent['order_id'])->value('provider'))->toBe('local')
        ->and(DB::table('payments')->count())->toBe(1);
    app(ConsumeEvent::class)->consume((string) $paidEvent->id, 'checkout.order-paid');
    expect(DB::table('consumer_receipts')->where('event_id', $paidEvent->id)->where('consumer', 'checkout.order-paid')->value('status'))->toBe('complete');
});

it('CheckoutOrdersFree confirmation screen recovers pending status and ignores a forged success URL', function (): void {
    $catalog = freeCatalog();
    $this->get('/')->assertOk();
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
    expect($quote['grandTotal']['amount'])->toBe(0)
        ->and($quote['digitalOnly'])->toBeTrue();
    $this->postJson('/store-api/v1/checkout-quotes/'.$quote['id'].'/accept', [
        'fingerprint' => $quote['fingerprint'],
        'policyAcknowledged' => true,
    ])->assertOk();
    $session = $this->postJson('/store-api/v1/checkout-sessions', [
        'quoteId' => $quote['id'],
        'fingerprint' => $quote['fingerprint'],
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');
    expect($session['zeroTotal'])->toBeTrue()
        ->and($session['orderStatus'])->toBe('confirmed')
        ->and($session['attemptStatus'])->toBe('succeeded')
        ->and($session['providerRedirect'])->toBeNull();
    $this->from('/checkout/'.$quote['id'])->post('/checkout/'.$quote['id'].'/pay', [
        'fingerprint' => $quote['fingerprint'],
    ])->assertRedirect('/checkout/orders/'.$session['orderId']);
    $orderId = $session['orderId'];
    $attemptId = $session['id'];
    $this->get('/checkout/orders/'.$orderId.'?status=success&payment_intent=pi_forged')
        ->assertOk()
        ->assertSee('Order confirmed')
        ->assertSee('USD 0.00')
        ->assertDontSee('paid')
        ->assertDontSee('guest@example.test');
    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('confirmed')
        ->and(DB::table('payments')->where('order_id', $orderId)->value('provider'))->toBe('local');
    $this->get('/checkout/payment/'.$attemptId.'/return?session_id=cs_forged&payment_intent=pi_forged')
        ->assertOk()
        ->assertDontSee('paid');
    $this->flushSession();
    $this->get('/checkout/orders/'.$orderId.'?status=success')->assertNotFound();
    $this->get('/checkout/orders/'.(string) Str::uuid().'?status=success')->assertNotFound();
    expect(DB::table('orders')->count())->toBe(1);

    $pendingGuest = CartOwner::guest('guest-pending-status');
    $pendingCart = app(UpdateCart::class)->replaceLine($pendingGuest, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    pricingSavedCoupon($catalog['owner'], [
        'code' => 'SAVE10',
        'max_redemptions' => 25,
        'max_redemptions_per_customer' => null,
    ]);
    $pendingQuote = app(CreateQuote::class)->create($pendingGuest, checkoutQuoteInput($pendingCart['revision'], [
        'coupon' => 'SAVE10',
    ]));
    app(AcceptQuote::class)->accept($pendingGuest, $pendingQuote['id'], $pendingQuote['fingerprint'], true);
    $pending = app(CreateCheckoutIntent::class)->create($pendingGuest, $pendingQuote['id'], (string) Str::uuid());
    expect($pending['zero_total'])->toBeFalse()
        ->and($pending['order_status'])->toBe('pending');
    $this->withSession(['guest_identity' => 'guest-pending-status'])
        ->get('/checkout/orders/'.$pending['order_id'].'?status=success&payment_intent=pi_forged')
        ->assertOk()
        ->assertSee('still processing')
        ->assertSee('Check status again')
        ->assertDontSee('Order confirmed')
        ->assertHeader('Refresh', '5');
    expect(DB::table('orders')->where('id', $pending['order_id'])->value('status'))->toBe('pending')
        ->and(DB::table('payments')->where('order_id', $pending['order_id'])->count())->toBe(0);
    DB::table('orders')->where('id', $pending['order_id'])->update(['status' => 'confirmed', 'updated_at' => now()]);
    DB::table('payment_attempts')->where('id', $pending['id'])->update(['status' => 'succeeded', 'updated_at' => now()]);
    $this->withSession(['guest_identity' => 'guest-pending-status'])
        ->get('/checkout/orders/'.$pending['order_id'])
        ->assertOk()
        ->assertSee('Order confirmed')
        ->assertDontSee('still processing')
        ->assertHeaderMissing('Refresh');
    expect(class_exists(OrderStatusController::class))->toBeTrue();
});

it('CheckoutOrdersFree HTTP keeps privileged staff behind two-factor and leaves the storefront closed', function (): void {
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
        ->get('/checkout/orders/'.(string) Str::uuid())
        ->assertNotFound();
    $this->get('/')->assertOk();
});
