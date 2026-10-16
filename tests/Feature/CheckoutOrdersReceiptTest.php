<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\ReadAdminOrders;
use App\Domain\CheckoutOrders\Actions\ReadCustomerOrders;
use App\Domain\CheckoutOrders\Actions\ResendOrderReceipt;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Platform\Actions\ConsumeEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-16T12:00:00Z')->utc());
});
afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{owner: StaffAccount, digital_variant: string}
 */
function receiptCatalog(): array
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

function receiptCustomer(string $email): CustomerAccount
{
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => $email,
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true, 'session_version' => 1, 'email_verified_at' => now(),
    ])->save();

    return $customer;
}

/**
 * @return array{order_id: string, number: string, email: string}
 */
function receiptPaidOrder(string $variantId, CustomerAccount $customer): array
{
    $owner = CartOwner::customer($customer->id);
    $cart = app(UpdateCart::class)->replaceLine($owner, [
        'variant_id' => $variantId, 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($owner, checkoutQuoteInput($cart['revision'], [
        'email' => $customer->email,
        'coupon' => 'FREE100',
        'shipping' => null,
    ]));
    app(AcceptQuote::class)->accept($owner, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($owner, $quote['id'], (string) Str::uuid());
    $paidEvent = DB::table('outbox_events')->where('event_type', 'checkout.order.paid')
        ->where('intent_key', 'order:paid:'.$intent['order_id'])->first();
    expect($paidEvent)->not->toBeNull();
    app(ConsumeEvent::class)->consume((string) $paidEvent->id, 'checkout.order-paid');
    app(ConsumeEvent::class)->consume((string) $paidEvent->id, 'checkout.order-paid');

    return [
        'order_id' => $intent['order_id'],
        'number' => (string) DB::table('orders')->where('id', $intent['order_id'])->value('number'),
        'email' => $customer->email,
        'event_id' => (string) $paidEvent->id,
    ];
}

it('CheckoutOrdersReceipt queues one event-derived receipt and shows the owner frozen history', function (): void {
    $catalog = receiptCatalog();
    $alice = receiptCustomer('alice@example.test');
    $bob = receiptCustomer('bob@example.test');
    $paid = receiptPaidOrder($catalog['digital_variant'], $alice);
    expect(DB::table('order_receipt_intents')->where('order_id', $paid['order_id'])->count())->toBe(1);
    $intent = DB::table('order_receipt_intents')->where('order_id', $paid['order_id'])->first();
    expect($intent->kind)->toBe('event')
        ->and($intent->template)->toBe('order-receipt')
        ->and($intent->recipient)->toBe('alice@example.test')
        ->and($intent->status)->toBe('queued')
        ->and($intent->intent_key)->toBe('receipt:paid:'.$paid['order_id'])
        ->and($intent->source_event_id)->toBe($paid['event_id']);
    expect(DB::table('order_events')->where('order_id', $paid['order_id'])->where('type', 'paid')->count())->toBe(1)
        ->and(DB::table('order_events')->where('order_id', $paid['order_id'])->where('type', 'receipt_queued')->count())->toBe(1);
    Mail::assertNothingOutgoing();
    $timeline = app(ReadAdminOrders::class)->detail($catalog['owner'], $paid['order_id'])['timeline'];
    expect($timeline)->toHaveCount(2)
        ->and($timeline[0]['type'])->toBe('paid')
        ->and($timeline[1]['type'])->toBe('receipt_queued')
        ->and($timeline[0]['amount']['amount'] ?? 0)->toBe(0);
    $own = app(ReadCustomerOrders::class)->detail($alice, $paid['order_id']);
    expect($own['number'])->toBe($paid['number'])
        ->and($own['email'])->toBe('alice@example.test')
        ->and($own['status'])->toBe('confirmed')
        ->and($own['totals']['grand_total']['amount'])->toBe(0)
        ->and($own['lines'][0]['sku'])->toBe('GUIDE-001')
        ->and($own['receipt']['template'])->toBe('order-receipt')
        ->and($own['timeline'][0]['type'])->toBe('paid')
        ->and($own)->not->toHaveKey('payload')
        ->and($own)->not->toHaveKey('notes');
    $list = app(ReadCustomerOrders::class)->list($alice);
    expect($list['data'])->toHaveCount(1)->and($list['data'][0]['id'])->toBe($paid['order_id']);
    expect(fn () => app(ReadCustomerOrders::class)->detail($bob, $paid['order_id']))
        ->toThrow(NotFoundHttpException::class);
    expect(app(ReadCustomerOrders::class)->list($bob)['data'])->toBe([]);
    $this->actingAs($alice, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/orders')->assertOk()->assertSee($paid['number'])->assertSee('alice@example.test');
    $this->get('/account/orders/'.$paid['order_id'])->assertOk()
        ->assertSee($paid['number'])
        ->assertSee('GUIDE-001')
        ->assertSee('USD 0.00')
        ->assertDontSee('guest_token_hash');
    $this->actingAs($bob, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/orders/'.$paid['order_id'])->assertNotFound();
    $this->get('/')->assertStatus(503);
});

it('CheckoutOrdersReceipt resends only with an explicit audited staff action', function (): void {
    $catalog = receiptCatalog();
    $alice = receiptCustomer('alice-resend@example.test');
    $paid = receiptPaidOrder($catalog['digital_variant'], $alice);
    $owner = $catalog['owner'];
    $key = (string) Str::uuid();
    $first = app(ResendOrderReceipt::class)->resend($owner, $paid['order_id'], $key);
    $replay = app(ResendOrderReceipt::class)->resend($owner, $paid['order_id'], $key);
    expect($first['id'])->toBe($replay['id'])
        ->and(DB::table('order_receipt_intents')->where('order_id', $paid['order_id'])->count())->toBe(2)
        ->and(DB::table('order_receipt_intents')->where('kind', 'resend')->count())->toBe(1);
    $second = app(ResendOrderReceipt::class)->resend($owner, $paid['order_id'], (string) Str::uuid());
    expect($second['id'])->not->toBe($first['id'])
        ->and(DB::table('order_receipt_intents')->where('kind', 'resend')->count())->toBe(2);
    $audit = DB::table('outbox_events')->where('intent_key', 'audit:order-receipt-resend:'.$first['id'])->first();
    expect($audit)->not->toBeNull();
    $payload = json_decode(Crypt::decryptString((string) $audit->payload), true);
    expect($payload['action'])->toBe('checkout.order.receipt_resent')
        ->and($payload['subject_id'])->toBe($paid['order_id'])
        ->and($payload)->not->toHaveKey('email');
    $detail = app(ReadAdminOrders::class)->detail($owner, $paid['order_id']);
    expect(array_column($detail['timeline'], 'type'))->toContain('receipt_resent');
    Mail::assertNothingOutgoing();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version])
        ->post('/admin/orders/'.$paid['order_id'].'/receipt', ['idempotency_key' => (string) Str::uuid()])
        ->assertRedirect('/admin/two-factor');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/orders/'.$paid['order_id'])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('OrderDetail')
        ->where('order.timeline.0.type', 'paid')
        ->where('canResendReceipt', true));
    $this->from('/admin/orders/'.$paid['order_id'])
        ->post('/admin/orders/'.$paid['order_id'].'/receipt', ['idempotency_key' => (string) Str::uuid()])
        ->assertRedirect('/admin/orders/'.$paid['order_id']);
    $fulfillment = new StaffAccount;
    $fulfillment->forceFill([
        'name' => 'Shipper', 'email' => 'shipper-receipt@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'fulfillment', 'permissions' => StaffPermissions::forRole('fulfillment'),
        'is_active' => true, 'session_version' => 1,
    ])->save();
    expect(fn () => app(ResendOrderReceipt::class)->resend($fulfillment, $paid['order_id'], (string) Str::uuid()))
        ->toThrow(AuthorizationException::class);
    $this->actingAs($fulfillment, 'staff')->withSession(catalogStaffSession($fulfillment))
        ->postJson('/admin/orders/'.$paid['order_id'].'/receipt', ['idempotency_key' => (string) Str::uuid()])
        ->assertForbidden();
    $this->get('/')->assertStatus(503);
});
