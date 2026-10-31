<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\AddOrderNote;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\ReadAdminOrders;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-16T12:00:00Z')->utc());
});
afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{owner: StaffAccount, physical_variant: string, digital_variant: string}
 */
function adminOrderCatalog(): array
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
        'on_hand' => 4,
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

it('CheckoutOrdersAdmin lets permitted staff search frozen orders with independent financial and fulfillment states', function (): void {
    $catalog = adminOrderCatalog();
    $physicalGuest = CartOwner::guest('guest-admin-physical-token');
    $physicalCart = app(UpdateCart::class)->replaceLine($physicalGuest, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $physicalQuote = app(CreateQuote::class)->create($physicalGuest, checkoutQuoteInput($physicalCart['revision'], [
        'email' => 'alex@example.test',
        'coupon' => 'SAVE10',
    ]));
    app(AcceptQuote::class)->accept($physicalGuest, $physicalQuote['id'], $physicalQuote['fingerprint'], true);
    $pending = app(CreateCheckoutIntent::class)->create($physicalGuest, $physicalQuote['id'], (string) Str::uuid());
    $digitalGuest = CartOwner::guest('guest-admin-digital-token');
    $digitalCart = app(UpdateCart::class)->replaceLine($digitalGuest, [
        'variant_id' => $catalog['digital_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $digitalQuote = app(CreateQuote::class)->create($digitalGuest, checkoutQuoteInput($digitalCart['revision'], [
        'email' => 'alex@example.test',
        'coupon' => 'FREE100',
        'shipping' => null,
    ]));
    app(AcceptQuote::class)->accept($digitalGuest, $digitalQuote['id'], $digitalQuote['fingerprint'], true);
    $paid = app(CreateCheckoutIntent::class)->create($digitalGuest, $digitalQuote['id'], (string) Str::uuid());
    $pendingNumber = (string) DB::table('orders')->where('id', $pending['order_id'])->value('number');
    $reader = app(ReadAdminOrders::class);
    $byNumber = $reader->search($catalog['owner'], ['q' => $pendingNumber]);
    expect($byNumber['data'])->toHaveCount(1)
        ->and($byNumber['data'][0]['id'])->toBe($pending['order_id'])
        ->and($byNumber['data'][0]['number'])->toBe($pendingNumber)
        ->and($byNumber['data'][0]['status'])->toBe('pending')
        ->and($byNumber['data'][0]['financial_status'])->toBe('unpaid')
        ->and($byNumber['data'][0]['fulfillment_status'])->toBe('unfulfilled')
        ->and($byNumber['data'][0]['download_status'])->toBe('not_required')
        ->and($byNumber['data'][0]['grand_total']['amount'])->toBe(2805);
    $byEmail = $reader->search($catalog['owner'], ['q' => 'alex@example.test', 'from' => '2026-10-16', 'to' => '2026-10-16']);
    expect(array_column($byEmail['data'], 'id'))->toEqualCanonicalizing([$pending['order_id'], $paid['order_id']]);
    $detail = $reader->detail($catalog['owner'], $pending['order_id']);
    expect($detail['email'])->toBe('alex@example.test')
        ->and($detail['status'])->toBe('pending')
        ->and($detail['financial_status'])->toBe('unpaid')
        ->and($detail['fulfillment_status'])->toBe('unfulfilled')
        ->and($detail['download_status'])->toBe('not_required')
        ->and($detail['totals']['merchandise']['amount'])->toBe(2500)
        ->and($detail['totals']['grand_total']['amount'])->toBe(2805)
        ->and($detail['lines'][0]['sku'])->toBe('LAMP-001')
        ->and($detail['lines'][0]['quantity'])->toBe(1)
        ->and($detail['lines'][0]['subtotal']['amount'])->toBe(2500)
        ->and($detail['shipping']['city'])->toBe('Los Angeles')
        ->and($detail)->not->toHaveKey('payload')
        ->and($detail)->not->toHaveKey('guest_token_hash');
    $paidDetail = $reader->detail($catalog['owner'], $paid['order_id']);
    expect($paidDetail['status'])->toBe('confirmed')
        ->and($paidDetail['financial_status'])->toBe('paid')
        ->and($paidDetail['fulfillment_status'])->toBe('not_required')
        ->and($paidDetail['download_status'])->toBe('entitled')
        ->and($paidDetail['totals']['grand_total']['amount'])->toBe(0);
    $empty = $reader->search($catalog['owner'], ['q' => 'nobody@example.test']);
    expect($empty['data'])->toBe([]);
    expect(fn () => $reader->detail($catalog['owner'], (string) Str::uuid()))->toThrow(NotFoundHttpException::class);
    $this->get('/')->assertOk();
});

it('CheckoutOrdersAdmin HTTP allows owner and fulfillment staff and denies content editors', function (): void {
    $catalog = adminOrderCatalog();
    $guest = CartOwner::guest('guest-admin-http-token');
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], [
        'email' => 'orders@example.test',
        'coupon' => 'SAVE10',
    ]));
    app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], (string) Str::uuid());
    $owner = $catalog['owner'];
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->get('/admin/orders')->assertRedirect('/admin/login');
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->getJson('/admin/orders')->assertUnauthorized();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version])
        ->get('/admin/orders')->assertRedirect('/admin/two-factor');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canReadOrders', true));
    $this->get('/admin/orders?q=orders@example.test')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Orders')
        ->where('orders.data.0.id', $intent['order_id'])
        ->where('orders.data.0.financial_status', 'unpaid')
        ->where('orders.data.0.fulfillment_status', 'unfulfilled')
        ->missing('orders.data.0.payload')
        ->missing('orders.data.0.guest_token_hash'));
    $this->get('/admin/orders/'.$intent['order_id'])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('OrderDetail')
        ->where('order.id', $intent['order_id'])
        ->where('order.financial_status', 'unpaid')
        ->where('order.fulfillment_status', 'unfulfilled')
        ->where('order.download_status', 'not_required')
        ->where('order.totals.grand_total.amount', 2805)
        ->missing('order.payload'));
    $fulfillment = new StaffAccount;
    $fulfillment->forceFill([
        'name' => 'Shipper', 'email' => 'shipper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'fulfillment', 'permissions' => StaffPermissions::forRole('fulfillment'), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($fulfillment, 'staff')->withSession(catalogStaffSession($fulfillment))
        ->get('/admin/orders/'.$intent['order_id'])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('OrderDetail')->where('order.id', $intent['order_id']));
    $editor = new StaffAccount;
    $editor->forceFill([
        'name' => 'Editor', 'email' => 'editor@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'content_editor', 'permissions' => StaffPermissions::forRole('content_editor'), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor))
        ->getJson('/admin/orders')->assertForbidden();
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor))
        ->getJson('/admin/orders/'.$intent['order_id'])->assertForbidden();
    $this->get('/')->assertOk();
});

it('CheckoutOrdersAdmin records internal notes with an audit fact and rejects unauthorized writers', function (): void {
    $catalog = adminOrderCatalog();
    $guest = CartOwner::guest('guest-admin-note-token');
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision']));
    app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], (string) Str::uuid());
    $owner = $catalog['owner'];
    $note = app(AddOrderNote::class)->add($owner, $intent['order_id'], 'Hold for address confirmation.');
    expect($note['body'])->toBe('Hold for address confirmation.');
    $detail = app(ReadAdminOrders::class)->detail($owner, $intent['order_id']);
    expect($detail['notes'])->toHaveCount(1)
        ->and($detail['notes'][0]['body'])->toBe('Hold for address confirmation.')
        ->and($detail['notes'][0]['staff_name'])->toBe('Example Owner');
    $audit = DB::table('outbox_events')->where('intent_key', 'audit:order-note:'.$note['id'])->first();
    expect($audit)->not->toBeNull()
        ->and($audit->event_type)->toBe('platform.audit.recorded');
    $payload = json_decode(Crypt::decryptString((string) $audit->payload), true);
    expect($payload['action'])->toBe('checkout.order.note_added')
        ->and($payload['subject_id'])->toBe($intent['order_id'])
        ->and($payload)->not->toHaveKey('body');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->from('/admin/orders/'.$intent['order_id'])
        ->post('/admin/orders/'.$intent['order_id'].'/notes', ['note' => 'Packed at the bench.'])
        ->assertRedirect('/admin/orders/'.$intent['order_id']);
    expect(DB::table('order_notes')->where('order_id', $intent['order_id'])->count())->toBe(2);
    $editor = $owner->fresh();
    $editor->forceFill(['role' => 'content_editor', 'permissions' => StaffPermissions::forRole('content_editor')])->save();
    expect(fn () => app(AddOrderNote::class)->add($editor, $intent['order_id'], 'Nope.'))
        ->toThrow(AuthorizationException::class);
    $this->actingAs($editor->fresh(), 'staff')->withSession(catalogStaffSession($editor->fresh()))
        ->postJson('/admin/orders/'.$intent['order_id'].'/notes', ['note' => 'Nope.'])
        ->assertForbidden();
    $this->get('/')->assertOk();
});
