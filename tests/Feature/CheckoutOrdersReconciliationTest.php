<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\AcceptProviderEvent;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\ReconcilePayment;
use App\Domain\CheckoutOrders\Actions\StartPayment;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Contracts\PaymentGateway;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\ConsumeEvent;
use App\Jobs\ProcessPaymentEvent;
use App\Jobs\ReconcilePendingPayments;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-14T00:00:00Z')->utc());
    config([
        'app.url' => 'http://127.0.0.1:8000',
        'services.stripe.secret' => 'sk_test_fixture',
        'services.stripe.account' => 'acct_fixture',
        'services.stripe.live' => false,
        'services.stripe.webhook_secret' => 'whsec_test_fixture',
    ]);
});
afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{owner: StaffAccount, physical_variant: string, digital_variant: string}
 */
function reconciliationCatalog(int $onHand = 4): array
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

/**
 * @return array<string, mixed>
 */
function reconciliationIntent(string $physicalVariant, string $guestToken = 'guest-reconcile-token'): array
{
    $guest = CartOwner::guest($guestToken);
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $physicalVariant, 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], ['coupon' => 'SAVE10']));
    app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true);

    return [
        'guest' => $guest,
        'intent' => app(CreateCheckoutIntent::class)->create($guest, $quote['id'], (string) Str::uuid()),
    ];
}

/**
 * @param  array<string, array{amount: int, session: string, capture: string}>  $attempts
 */
function fakeHostedThenPaidSessions(array &$attempts): void
{
    $opened = [];
    Http::fake(function (Request $request) use (&$attempts, &$opened) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/checkout/sessions')) {
            $attemptId = $request->data()['client_reference_id'] ?? '';
            if (! is_string($attemptId) || ! isset($attempts[$attemptId]) || isset($opened[$attemptId])) {
                return Http::response(['error' => ['message' => 'unexpected']], 500);
            }
            $opened[$attemptId] = true;
            $spec = $attempts[$attemptId];

            return Http::response(paidSessionPayload($spec['amount'], $attemptId, $spec['session'], $spec['capture'], open: true), 200);
        }
        if ($request->method() === 'GET') {
            foreach ($attempts as $attemptId => $spec) {
                if (str_contains($request->url(), '/v1/checkout/sessions/'.$spec['session'])) {
                    return Http::response(paidSessionPayload($spec['amount'], $attemptId, $spec['session'], $spec['capture'], open: false), 200);
                }
            }
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });
}

/**
 * @return array<string, mixed>
 */
function paidSessionPayload(int $amount, string $attemptId, string $sessionId, string $capture, bool $open): array
{
    return [
        'id' => $sessionId,
        'object' => 'checkout.session',
        'amount_total' => $amount,
        'currency' => 'usd',
        'client_reference_id' => $attemptId,
        'status' => $open ? 'open' : 'complete',
        'payment_status' => $open ? 'unpaid' : 'paid',
        'expires_at' => Carbon::parse('2026-10-14T00:30:00Z')->utc()->getTimestamp(),
        'url' => $open ? 'https://checkout.stripe.com/c/pay/'.$sessionId : null,
        'payment_intent' => $open ? null : $capture,
        'livemode' => false,
        'payment_method_types' => ['card'],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{payload: string, signature: string, event_id: string}
 */
function signedReconcileEvent(array $overrides = []): array
{
    $eventId = is_string($overrides['id'] ?? null) ? (string) $overrides['id'] : 'evt_test_paid_1';
    $object = is_array($overrides['object'] ?? null) ? $overrides['object'] : [];
    unset($overrides['object']);
    $payload = json_encode(array_replace([
        'id' => $eventId,
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'livemode' => false,
        'created' => 1760400000,
        'data' => [
            'object' => array_replace([
                'id' => 'cs_test_paid1',
                'object' => 'checkout.session',
                'client_reference_id' => '00000000-0000-0000-0000-000000000000',
                'amount_total' => 2805,
                'currency' => 'usd',
                'status' => 'complete',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_paid1',
            ], $object),
        ],
    ], $overrides), JSON_THROW_ON_ERROR);
    $timestamp = (string) Carbon::now()->timestamp;
    $secret = (string) config('services.stripe.webhook_secret');
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

    return ['payload' => $payload, 'signature' => $signature, 'event_id' => $eventId];
}

it('CheckoutOrdersReconciliation commits matching success once and ignores later unordered failure', function (): void {
    $catalog = reconciliationCatalog();
    $prepared = reconciliationIntent($catalog['physical_variant']);
    $intent = $prepared['intent'];
    $sessions = [
        $intent['id'] => ['amount' => 2805, 'session' => 'cs_test_paid1', 'capture' => 'pi_test_paid1'],
    ];
    fakeHostedThenPaidSessions($sessions);
    app(StartPayment::class)->start($prepared['guest'], $intent['id']);
    expect(DB::table('payment_attempts')->where('id', $intent['id'])->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('stock_reservations')->where('intent_id', $intent['order_id'])->value('status'))->toBe('active')
        ->and(DB::table('coupon_holds')->where('intent_id', $intent['order_id'])->value('status'))->toBe('active');

    $signed = signedReconcileEvent([
        'id' => 'evt_test_paid_success',
        'object' => ['client_reference_id' => $intent['id']],
    ]);
    $eventId = app(AcceptProviderEvent::class)->accept($signed['payload'], $signed['signature'], null);
    (new ProcessPaymentEvent($eventId))->handle();
    (new ProcessPaymentEvent($eventId))->handle();

    $expired = signedReconcileEvent([
        'id' => 'evt_test_paid_expired',
        'type' => 'checkout.session.expired',
        'object' => [
            'client_reference_id' => $intent['id'],
            'status' => 'expired',
            'payment_status' => 'unpaid',
            'payment_intent' => null,
        ],
    ]);
    $expiredId = app(AcceptProviderEvent::class)->accept($expired['payload'], $expired['signature'], null);
    (new ProcessPaymentEvent($expiredId))->handle();

    expect(DB::table('orders')->where('id', $intent['order_id'])->value('status'))->toBe('confirmed')
        ->and(DB::table('payment_attempts')->where('id', $intent['id'])->value('status'))->toBe('succeeded')
        ->and(DB::table('payments')->where('order_id', $intent['order_id'])->count())->toBe(1)
        ->and(DB::table('payments')->where('order_id', $intent['order_id'])->value('provider_capture_id'))->toBe('pi_test_paid1')
        ->and(DB::table('stock_reservations')->where('intent_id', $intent['order_id'])->value('status'))->toBe('committed')
        ->and(DB::table('coupon_holds')->where('intent_id', $intent['order_id'])->value('status'))->toBe('committed')
        ->and(DB::table('outbox_events')->where('event_type', 'checkout.order.paid')->where('intent_key', 'order:paid:'.$intent['order_id'])->count())->toBe(1)
        ->and(DB::table('provider_events')->where('id', $eventId)->value('processing_state'))->toBe('processed')
        ->and(DB::table('provider_events')->where('id', $expiredId)->value('processing_state'))->toBe('processed');
    $paidEvent = DB::table('outbox_events')->where('event_type', 'checkout.order.paid')->first();
    $payload = json_decode(Crypt::decryptString((string) $paidEvent->payload), true);
    expect($payload['order_id'])->toBe($intent['order_id'])
        ->and($payload['attempt_id'])->toBe($intent['id'])
        ->and($payload['amount'])->toBe(2805)
        ->and($payload['currency'])->toBe('USD')
        ->and($payload)->not->toHaveKey('email');
    app(ConsumeEvent::class)->consume((string) $paidEvent->id, 'checkout.order-paid');
    expect(DB::table('consumer_receipts')->where('event_id', $paidEvent->id)->where('consumer', 'checkout.order-paid')->value('status'))->toBe('complete');
});

it('CheckoutOrdersReconciliation queries unknown attempts and recovers a crash after provider capture', function (): void {
    $catalog = reconciliationCatalog();
    $prepared = reconciliationIntent($catalog['physical_variant'], 'guest-unknown-reconcile');
    $intent = $prepared['intent'];
    $sessions = [
        $intent['id'] => ['amount' => 2805, 'session' => 'cs_test_crash1', 'capture' => 'pi_test_crash1'],
    ];
    fakeHostedThenPaidSessions($sessions);
    app(StartPayment::class)->start($prepared['guest'], $intent['id']);

    $gateway = app(PaymentGateway::class);
    $frozen = new PaymentIntent($intent['id'], 2805, 'USD', 'acct_fixture', false);
    $observed = $gateway->retrieveSession($frozen, 'cs_test_crash1');
    expect($observed->session->isPaid())->toBeTrue()
        ->and(DB::table('orders')->where('id', $intent['order_id'])->value('status'))->toBe('pending')
        ->and(DB::table('payment_attempts')->where('id', $intent['id'])->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('outbox_events')->where('event_type', 'checkout.order.paid')->count())->toBe(0);

    DB::table('payment_attempts')->where('id', $intent['id'])->update(['status' => 'unknown']);
    app(ReconcilePayment::class)->reconcile($intent['id']);
    expect(DB::table('payment_attempts')->where('id', $intent['id'])->value('status'))->toBe('succeeded')
        ->and(DB::table('orders')->where('id', $intent['order_id'])->value('status'))->toBe('confirmed')
        ->and(DB::table('payments')->where('attempt_id', $intent['id'])->value('provider_capture_id'))->toBe('pi_test_crash1')
        ->and(DB::table('outbox_events')->where('event_type', 'checkout.order.paid')->count())->toBe(1);

    $second = reconciliationIntent($catalog['physical_variant'], 'guest-job-unknown');
    $sessions[$second['intent']['id']] = ['amount' => 2805, 'session' => 'cs_test_job1', 'capture' => 'pi_test_job1'];
    app(StartPayment::class)->start($second['guest'], $second['intent']['id']);
    expect(DB::table('payment_attempts')->where('id', $second['intent']['id'])->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('payment_attempts')->where('id', $second['intent']['id'])->value('provider_session_id'))->toBe('cs_test_job1');
    DB::table('payment_attempts')->where('id', $second['intent']['id'])->update(['status' => 'unknown']);
    (new ReconcilePendingPayments)->handle();
    expect(DB::table('payment_attempts')->where('id', $second['intent']['id'])->value('status'))->toBe('succeeded')
        ->and(Http::recorded()->filter(fn (array $pair): bool => $pair[0]->method() === 'GET')->count())->toBeGreaterThan(0);
});

it('CheckoutOrdersReconciliation refuses a mismatched capture and does not trust a return URL', function (): void {
    $catalog = reconciliationCatalog();
    $prepared = reconciliationIntent($catalog['physical_variant'], 'guest-mismatch-reconcile');
    $intent = $prepared['intent'];
    Http::fake(function (Request $request) use ($intent) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/checkout/sessions')) {
            return Http::response(paidSessionPayload(2805, $intent['id'], 'cs_test_mismatch', 'pi_unused', open: true), 200);
        }
        if ($request->method() === 'GET' && str_contains($request->url(), '/v1/checkout/sessions/cs_test_mismatch')) {
            return Http::response(paidSessionPayload(9999, $intent['id'], 'cs_test_mismatch', 'pi_mismatch', open: false), 200);
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });
    app(StartPayment::class)->start($prepared['guest'], $intent['id']);
    app(ReconcilePayment::class)->reconcile($intent['id']);
    expect(DB::table('orders')->where('id', $intent['order_id'])->value('status'))->toBe('pending')
        ->and(DB::table('payment_attempts')->where('id', $intent['id'])->value('status'))->not->toBe('succeeded')
        ->and(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('stock_reservations')->where('intent_id', $intent['order_id'])->value('status'))->toBe('active');

    $this->get('/checkout/payment/'.$intent['id'].'/return?payment_intent=pi_forged')->assertOk()->assertSee('Payment is still processing');
    expect(DB::table('orders')->where('id', $intent['order_id'])->value('status'))->toBe('pending');
});

it('CheckoutOrdersReconciliation HTTP keeps privileged staff behind two-factor and leaves the storefront closed', function (): void {
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
});
