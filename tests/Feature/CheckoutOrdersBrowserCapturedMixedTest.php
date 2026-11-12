<?php

declare(strict_types=1);

use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\ReadAdminOrders;
use App\Domain\CheckoutOrders\Actions\ReadCart;
use App\Domain\CheckoutOrders\Actions\ReadCustomerOrders;
use App\Domain\CheckoutOrders\Actions\StartPayment;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Fulfillment\Actions\ReadDownloads;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Platform\Actions\ConsumeEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-16T18:00:00Z')->utc());
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
 * @return array{owner: mixed, customer: CustomerAccount, free_order_id: string}
 */
function browserMixedFreeOrder(): array
{
    $catalog = freeCatalog(10);
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Mixed Shopper',
        'email' => 'mixed@example.test',
        'password' => Hash::make('Fictional shopper passphrase 471!'),
        'is_active' => true,
        'session_version' => 1,
        'email_verified_at' => now(),
    ])->save();
    $owner = CartOwner::customer($customer->id);
    $cart = app(UpdateCart::class)->replaceLine($owner, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => 2, 'expected_revision' => 1,
    ]);
    $cart = app(UpdateCart::class)->replaceLine($owner, [
        'variant_id' => $catalog['digital_variant'], 'quantity' => 1, 'expected_revision' => $cart['revision'],
    ]);
    $quote = app(CreateQuote::class)->create($owner, checkoutQuoteInput($cart['revision'], [
        'email' => $customer->email,
        'coupon' => 'FREE100',
    ]));
    app(AcceptQuote::class)->accept($owner, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($owner, $quote['id'], (string) Str::uuid());
    app(StartPayment::class)->start($owner, $intent['id']);

    return [
        'owner' => $catalog['owner'],
        'customer' => $customer,
        'free_order_id' => $intent['order_id'],
    ];
}

it('CheckoutOrdersBrowserCapturedMixed staff list prefers a captured mixed order and flashes Refund submitted', function (): void {
    $fixture = browserMixedFreeOrder();
    $owner = $fixture['owner'];
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/orders')->assertOk();
    $listed = app(ReadAdminOrders::class)->search($owner, []);
    expect($listed['data'][0]['id'])->not->toBe($fixture['free_order_id'])
        ->and($listed['data'][0]['financial_status'])->toBe('paid');
    $paidId = $listed['data'][0]['id'];
    expect((int) DB::table('orders')->where('id', $paidId)->value('zero_total'))->toBe(0)
        ->and(DB::table('payments')->where('order_id', $paidId)->value('provider'))->toBe('stripe');
    $lampLine = (string) DB::table('order_lines')->where('order_id', $paidId)
        ->where('title', 'Studio Lamp')->value('id');
    expect($lampLine)->not->toBe('');
    $this->from('/admin/orders/'.$paidId)
        ->post('/admin/orders/'.$paidId.'/refunds', [
            'lines' => [['id' => $lampLine, 'quantity' => 1]],
            'shipping_amount' => 0,
            'reason' => 'One lamp arrived damaged.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/admin/orders/'.$paidId);
    $this->get('/admin/orders/'.$paidId)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('OrderDetail')
        ->where('refundSaved', true));
    $customerOrders = app(ReadCustomerOrders::class)->list($fixture['customer']);
    expect($customerOrders['data'][0]['id'])->toBe($paidId);
    $this->get('/')->assertStatus(503);
});

it('CheckoutOrdersBrowserCapturedMixed leftover cart digital does not add a second Lighting guide', function (): void {
    $fixture = browserMixedFreeOrder();
    $customer = $fixture['customer'];
    $owner = $fixture['owner'];
    $entitlement = DB::table('entitlements')->where('customer_id', $customer->id)->first();
    expect($entitlement)->not->toBeNull();
    DB::table('entitlements')->where('id', $entitlement->id)->update(['downloads_used' => 1, 'updated_at' => now()]);
    $cartOwner = CartOwner::customer($customer->id);
    $cart = app(ReadCart::class)->snapshot($cartOwner);
    $guideId = (string) DB::table('products')->where('slug', 'studio-guide')->value('id');
    $digital = (string) DB::table('product_variants')->where('product_id', $guideId)->value('id');
    app(UpdateCart::class)->replaceLine($cartOwner, [
        'variant_id' => $digital, 'quantity' => 1, 'expected_revision' => $cart['revision'],
    ]);
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/orders')->assertOk();
    $paidId = app(ReadAdminOrders::class)->search($owner, [])['data'][0]['id'];
    expect($paidId)->not->toBe($fixture['free_order_id']);
    $paidEvent = DB::table('outbox_events')->where('event_type', 'checkout.order.paid')
        ->where('intent_key', 'order:paid:'.$paidId)->first();
    expect($paidEvent)->not->toBeNull();
    app(ConsumeEvent::class)->consume((string) $paidEvent->id, 'fulfillment.entitlements');
    expect(DB::table('order_lines')->where('order_id', $paidId)->where('type', 'digital')->count())->toBe(0);
    $area = app(ReadDownloads::class)->forCustomer($customer);
    $labels = [];
    foreach ($area['orders'] as $order) {
        foreach ($order['downloads'] as $download) {
            $labels[] = $download['label'];
        }
    }
    expect($labels)->toBe(['Lighting guide'])
        ->and($area['orders'][0]['downloads'][0]['downloads_remaining'])->toBe(4);
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/downloads')->assertOk()
        ->assertSee('Lighting guide')
        ->assertSee('4 of 5 downloads remaining');
    expect(substr_count((string) $this->get('/account/downloads')->getContent(), 'Lighting guide ·'))->toBe(1);
    $this->get('/')->assertStatus(503);
});

it('CheckoutOrdersBrowserCapturedMixed HTTP still requires fresh 2FA before a refund posts', function (): void {
    $fixture = browserMixedFreeOrder();
    $owner = $fixture['owner'];
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/orders')->assertOk();
    $paidId = app(ReadAdminOrders::class)->search($owner, [])['data'][0]['id'];
    $lampLine = (string) DB::table('order_lines')->where('order_id', $paidId)
        ->where('title', 'Studio Lamp')->value('id');
    $this->actingAs($owner, 'staff')->withSession([
        'staff_session_version' => $owner->session_version,
        'staff_two_factor_verified' => true,
        'staff_reauthenticated_at' => 0,
    ]);
    $this->from('/admin/orders/'.$paidId)
        ->post('/admin/orders/'.$paidId.'/refunds', [
            'lines' => [['id' => $lampLine, 'quantity' => 1]],
            'shipping_amount' => 0,
            'reason' => 'One lamp arrived damaged.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/admin/two-factor');
    $this->get('/')->assertStatus(503);
});
