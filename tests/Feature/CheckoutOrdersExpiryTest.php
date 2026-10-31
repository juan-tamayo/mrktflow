<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\ExpireCheckout;
use App\Domain\CheckoutOrders\Actions\ReconcilePayment;
use App\Domain\CheckoutOrders\Actions\StartPayment;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Jobs\ExpireCheckoutSessions;
use Illuminate\Http\Client\Request;
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
        'services.stripe.webhook_secret' => 'whsec_test_fixture',
    ]);
});
afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{owner: StaffAccount, physical_variant: string, digital_variant: string}
 */
function expiryCatalog(int $onHand = 4): array
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
function expiryIntent(string $physicalVariant, string $guestToken = 'guest-expiry-token'): array
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
 * @param  array<string, array{amount: int, session: string, capture: string, observe?: string, expire?: string}>  $attempts
 */
function fakeExpirySessions(array &$attempts): void
{
    Http::fake(function (Request $request) use (&$attempts) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/checkout/sessions') && ! str_contains($request->url(), '/expire')) {
            $attemptId = $request->data()['client_reference_id'] ?? '';
            if (is_string($attemptId) && isset($attempts[$attemptId])) {
                return Http::response(expirySessionPayload($attempts[$attemptId], $attemptId, 'open'), 200);
            }
        }
        foreach ($attempts as $attemptId => $spec) {
            $session = $spec['session'];
            if (! str_contains($request->url(), '/v1/checkout/sessions/'.$session)) {
                continue;
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/expire')) {
                $mode = $spec['expire'] ?? 'expired';
                if ($mode === 'outage') {
                    return Http::response(['error' => ['message' => 'unavailable']], 500);
                }
                $attempts[$attemptId]['observe'] = $mode === 'paid' ? 'paid' : 'expired';

                return Http::response(expirySessionPayload($attempts[$attemptId], $attemptId, $attempts[$attemptId]['observe']), 200);
            }
            if ($request->method() === 'GET') {
                $state = $spec['observe'] ?? 'open';
                if ($state === 'outage') {
                    return Http::response(['error' => ['message' => 'unavailable']], 500);
                }

                return Http::response(expirySessionPayload($spec, $attemptId, $state), 200);
            }
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });
}

/**
 * @param  array{amount: int, session: string, capture: string, observe?: string, expire?: string}  $spec
 * @return array<string, mixed>
 */
function expirySessionPayload(array $spec, string $attemptId, string $state): array
{
    $open = $state === 'open';
    $paid = $state === 'paid';

    return [
        'id' => $spec['session'],
        'object' => 'checkout.session',
        'amount_total' => $spec['amount'],
        'currency' => 'usd',
        'client_reference_id' => $attemptId,
        'status' => $paid ? 'complete' : ($open ? 'open' : 'expired'),
        'payment_status' => $paid ? 'paid' : 'unpaid',
        'expires_at' => Carbon::parse('2026-10-16T00:30:00Z')->utc()->getTimestamp(),
        'url' => $open ? 'https://checkout.stripe.com/c/pay/'.$spec['session'] : null,
        'payment_intent' => $paid ? $spec['capture'] : null,
        'livemode' => false,
        'payment_method_types' => ['card'],
    ];
}

it('CheckoutOrdersExpiry expires an open provider session before releasing stock and coupon holds', function (): void {
    $catalog = expiryCatalog();
    $prepared = expiryIntent($catalog['physical_variant']);
    $intent = $prepared['intent'];
    $sessions = [
        $intent['id'] => ['amount' => 2805, 'session' => 'cs_test_expire1', 'capture' => 'pi_unused', 'observe' => 'open', 'expire' => 'expired'],
    ];
    fakeExpirySessions($sessions);
    app(StartPayment::class)->start($prepared['guest'], $intent['id']);
    expect(DB::table('payment_attempts')->where('id', $intent['id'])->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('stock_reservations')->where('intent_id', $intent['order_id'])->value('status'))->toBe('active')
        ->and((int) DB::table('inventory_balances')->where('variant_id', $catalog['physical_variant'])->value('reserved'))->toBe(1);

    Carbon::setTestNow(Carbon::parse('2026-10-16T00:31:00Z')->utc());
    app(ExpireCheckout::class)->expire($intent['id']);

    $expireCalls = Http::recorded()->filter(
        fn (array $pair): bool => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), '/expire'),
    );
    expect($expireCalls)->toHaveCount(1)
        ->and(DB::table('payment_attempts')->where('id', $intent['id'])->value('status'))->toBe('expired')
        ->and(DB::table('orders')->where('id', $intent['order_id'])->value('status'))->toBe('pending')
        ->and(DB::table('stock_reservations')->where('intent_id', $intent['order_id'])->value('status'))->toBe('released')
        ->and(DB::table('coupon_holds')->where('intent_id', $intent['order_id'])->value('status'))->toBe('released')
        ->and((int) DB::table('inventory_balances')->where('variant_id', $catalog['physical_variant'])->value('reserved'))->toBe(0)
        ->and(DB::table('payments')->count())->toBe(0);
});

it('CheckoutOrdersExpiry retains holds when the provider is unavailable', function (): void {
    $catalog = expiryCatalog();
    $prepared = expiryIntent($catalog['physical_variant'], 'guest-expiry-outage');
    $intent = $prepared['intent'];
    $sessions = [
        $intent['id'] => ['amount' => 2805, 'session' => 'cs_test_outage1', 'capture' => 'pi_unused', 'observe' => 'open', 'expire' => 'outage'],
    ];
    fakeExpirySessions($sessions);
    app(StartPayment::class)->start($prepared['guest'], $intent['id']);
    Carbon::setTestNow(Carbon::parse('2026-10-16T00:31:00Z')->utc());
    app(ExpireCheckout::class)->expire($intent['id']);

    expect(DB::table('payment_attempts')->where('id', $intent['id'])->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('orders')->where('id', $intent['order_id'])->value('status'))->toBe('pending')
        ->and(DB::table('stock_reservations')->where('intent_id', $intent['order_id'])->value('status'))->toBe('active')
        ->and(DB::table('coupon_holds')->where('intent_id', $intent['order_id'])->value('status'))->toBe('active')
        ->and((int) DB::table('inventory_balances')->where('variant_id', $catalog['physical_variant'])->value('reserved'))->toBe(1);
});

it('CheckoutOrdersExpiry re-reserves late success after expiry when stock remains', function (): void {
    $catalog = expiryCatalog();
    $prepared = expiryIntent($catalog['physical_variant'], 'guest-expiry-late-ok');
    $intent = $prepared['intent'];
    $sessions = [
        $intent['id'] => ['amount' => 2805, 'session' => 'cs_test_lateok', 'capture' => 'pi_lateok', 'observe' => 'open', 'expire' => 'expired'],
    ];
    fakeExpirySessions($sessions);
    app(StartPayment::class)->start($prepared['guest'], $intent['id']);
    Carbon::setTestNow(Carbon::parse('2026-10-16T00:31:00Z')->utc());
    app(ExpireCheckout::class)->expire($intent['id']);
    expect(DB::table('stock_reservations')->where('intent_id', $intent['order_id'])->value('status'))->toBe('released');

    $sessions[$intent['id']]['observe'] = 'paid';
    app(ReconcilePayment::class)->reconcile($intent['id']);

    expect(DB::table('payment_attempts')->where('id', $intent['id'])->value('status'))->toBe('succeeded')
        ->and(DB::table('orders')->where('id', $intent['order_id'])->value('status'))->toBe('confirmed')
        ->and(DB::table('payments')->where('attempt_id', $intent['id'])->value('provider_capture_id'))->toBe('pi_lateok')
        ->and(DB::table('stock_reservations')->where('intent_id', $intent['order_id'])->value('status'))->toBe('committed')
        ->and(DB::table('coupon_holds')->where('intent_id', $intent['order_id'])->value('status'))->toBe('committed')
        ->and((int) DB::table('inventory_balances')->where('variant_id', $catalog['physical_variant'])->value('reserved'))->toBe(1)
        ->and(DB::table('outbox_events')->where('event_type', 'checkout.order.paid')->where('intent_key', 'order:paid:'.$intent['order_id'])->count())->toBe(1);
});

it('CheckoutOrdersExpiry holds a late paid order when a competing purchase took the last unit', function (): void {
    $catalog = expiryCatalog(1);
    $first = expiryIntent($catalog['physical_variant'], 'guest-expiry-first');
    $intent = $first['intent'];
    $sessions = [
        $intent['id'] => ['amount' => 2805, 'session' => 'cs_test_latehold', 'capture' => 'pi_latehold', 'observe' => 'open', 'expire' => 'expired'],
    ];
    fakeExpirySessions($sessions);
    app(StartPayment::class)->start($first['guest'], $intent['id']);
    Carbon::setTestNow(Carbon::parse('2026-10-16T00:31:00Z')->utc());
    app(ExpireCheckout::class)->expire($intent['id']);
    expect((int) DB::table('inventory_balances')->where('variant_id', $catalog['physical_variant'])->value('reserved'))->toBe(0);

    $second = expiryIntent($catalog['physical_variant'], 'guest-expiry-competitor');
    expect(DB::table('stock_reservations')->where('intent_id', $second['intent']['order_id'])->value('status'))->toBe('active')
        ->and((int) DB::table('inventory_balances')->where('variant_id', $catalog['physical_variant'])->value('reserved'))->toBe(1);

    $sessions[$intent['id']]['observe'] = 'paid';
    app(ReconcilePayment::class)->reconcile($intent['id']);

    expect(DB::table('payment_attempts')->where('id', $intent['id'])->value('status'))->toBe('succeeded')
        ->and(DB::table('orders')->where('id', $intent['order_id'])->value('status'))->toBe('on_hold')
        ->and(DB::table('payments')->where('attempt_id', $intent['id'])->value('provider_capture_id'))->toBe('pi_latehold')
        ->and(DB::table('stock_reservations')->where('intent_id', $intent['order_id'])->value('status'))->toBe('released')
        ->and((int) DB::table('inventory_balances')->where('variant_id', $catalog['physical_variant'])->value('reserved'))->toBe(1)
        ->and(DB::table('outbox_events')->where('event_type', 'checkout.order.paid')->where('intent_key', 'order:paid:'.$intent['order_id'])->count())->toBe(1)
        ->and(DB::table('outbox_events')->where('intent_key', 'audit:order-hold:'.$intent['order_id'])->count())->toBe(1);
    $audit = DB::table('outbox_events')->where('intent_key', 'audit:order-hold:'.$intent['order_id'])->first();
    $payload = json_decode(Crypt::decryptString((string) $audit->payload), true);
    expect($payload['action'])->toBe('checkout.order.on-hold')
        ->and($payload['subject_id'])->toBe($intent['order_id'])
        ->and($payload['metadata']['reason_code'])->toBe('late-success-stock');
});

it('CheckoutOrdersExpiry scheduler expires due sessions and leaves later holds untouched', function (): void {
    $catalog = expiryCatalog();
    $due = expiryIntent($catalog['physical_variant'], 'guest-expiry-due');
    $later = expiryIntent($catalog['physical_variant'], 'guest-expiry-later');
    $sessions = [
        $due['intent']['id'] => ['amount' => 2805, 'session' => 'cs_test_due', 'capture' => 'pi_unused_due', 'observe' => 'open', 'expire' => 'expired'],
        $later['intent']['id'] => ['amount' => 2805, 'session' => 'cs_test_later', 'capture' => 'pi_unused_later', 'observe' => 'open', 'expire' => 'expired'],
    ];
    fakeExpirySessions($sessions);
    app(StartPayment::class)->start($due['guest'], $due['intent']['id']);
    app(StartPayment::class)->start($later['guest'], $later['intent']['id']);
    DB::table('payment_attempts')->where('id', $later['intent']['id'])->update([
        'expires_at' => '2026-10-16 01:00:00',
        'updated_at' => now(),
    ]);
    Carbon::setTestNow(Carbon::parse('2026-10-16T00:31:00Z')->utc());
    (new ExpireCheckoutSessions)->handle();

    expect(DB::table('payment_attempts')->where('id', $due['intent']['id'])->value('status'))->toBe('expired')
        ->and(DB::table('stock_reservations')->where('intent_id', $due['intent']['order_id'])->value('status'))->toBe('released')
        ->and(DB::table('payment_attempts')->where('id', $later['intent']['id'])->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('stock_reservations')->where('intent_id', $later['intent']['order_id'])->value('status'))->toBe('active');
});

it('CheckoutOrdersExpiry HTTP keeps privileged staff behind two-factor and leaves the storefront closed', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/products')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/products', ['product' => ['title' => 'Denied']])->assertForbidden();
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper-expiry@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->postJson('/store-api/v1/checkout-sessions/'.(string) Str::uuid().'/payment')
        ->assertStatus(409);
    $this->get('/')->assertOk();
});
