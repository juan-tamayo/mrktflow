<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\CancelOrder;
use App\Domain\CheckoutOrders\Actions\CompleteFreeOrder;
use App\Domain\CheckoutOrders\Actions\ConfirmOrderPayment;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\StartPayment;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\PaymentSession;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Platform\Actions\ConsumeEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-16T16:00:00Z')->utc());
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
function cancellationCatalog(int $onHand = 4): array
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
function cancellationPaidPhysical(string $physicalVariant, string $guestToken, string $sessionId, string $captureId, int $quantity = 1): array
{
    $guest = CartOwner::guest($guestToken);
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $physicalVariant, 'quantity' => $quantity, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], ['coupon' => 'SAVE10']));
    app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], (string) Str::uuid());
    $amount = (int) $intent['grand_total']['amount'];
    Http::fake(function (Request $request) use ($intent, $sessionId, $amount): mixed {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/checkout/sessions')) {
            return Http::response([
                'id' => $sessionId,
                'object' => 'checkout.session',
                'amount_total' => $amount,
                'currency' => 'usd',
                'client_reference_id' => $intent['id'],
                'status' => 'open',
                'payment_status' => 'unpaid',
                'expires_at' => Carbon::parse('2026-10-16T16:30:00Z')->utc()->getTimestamp(),
                'url' => 'https://checkout.stripe.com/c/pay/'.$sessionId,
                'payment_intent' => null,
                'livemode' => false,
                'payment_method_types' => ['card'],
            ], 200);
        }

        return null;
    });
    app(StartPayment::class)->start($guest, $intent['id']);
    $payment = new PaymentIntent($intent['id'], $amount, 'USD', 'acct_fixture', false);
    app(ConfirmOrderPayment::class)->confirm($intent['id'], PaymentSession::observed(
        $payment,
        $payment,
        $sessionId,
        'complete',
        'paid',
        Carbon::parse('2026-10-16T16:30:00Z')->utc()->getTimestamp(),
        $captureId,
    ));
    $lineId = (string) DB::table('order_lines')->where('order_id', $intent['order_id'])->value('id');

    return $intent + ['line_id' => $lineId, 'capture_id' => $captureId, 'amount' => $amount];
}

/**
 * @return array<string, mixed>
 */
function cancellationFreeDigital(string $digitalVariant, string $guestToken): array
{
    $guest = CartOwner::guest($guestToken);
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $digitalVariant, 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], [
        'coupon' => 'FREE100',
        'shipping' => null,
    ]));
    app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], (string) Str::uuid());
    app(CompleteFreeOrder::class)->complete($intent['id']);
    $lineId = (string) DB::table('order_lines')->where('order_id', $intent['order_id'])->value('id');

    return $intent + ['line_id' => $lineId];
}

function fakeStripeCancellationRefund(string $status, string $providerRefundId, int $amount, string $captureId): void
{
    Http::fake(function (Request $request) use ($status, $providerRefundId, $amount, $captureId): mixed {
        $posted = $request->data();
        $meta = is_array($posted['metadata'] ?? null) ? $posted['metadata'] : [];
        $boundId = is_string($meta['refund_id'] ?? null) ? (string) $meta['refund_id'] : '';
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/refunds')) {
            $postedAmount = is_numeric($posted['amount'] ?? null) ? (int) $posted['amount'] : $amount;
            $id = $boundId !== '' ? 're_'.str_replace('-', '', $boundId) : $providerRefundId;

            return Http::response([
                'id' => $id,
                'object' => 'refund',
                'amount' => $postedAmount,
                'payment_intent' => $captureId,
                'status' => $status,
                'metadata' => $boundId === '' ? [] : ['refund_id' => $boundId],
            ], 200);
        }

        return null;
    });
}

it('CheckoutOrdersCancellation refunds remaining capture, releases unshipped allocations, and replays the durable fact', function (): void {
    $catalog = cancellationCatalog();
    $owner = $catalog['owner'];
    $paid = cancellationPaidPhysical($catalog['physical_variant'], 'guest-cancel-ok', 'cs_cancel_ok', 'pi_cancel_ok');
    fakeStripeCancellationRefund('succeeded', 're_cancel_ok', $paid['amount'], 'pi_cancel_ok');
    $key = (string) Str::uuid();
    $first = app(CancelOrder::class)->cancel($owner, $paid['order_id'], [
        'reason' => 'Shopper cancelled before shipment.',
    ], $key);
    expect($first['status'])->toBe('cancelled')
        ->and($first['financial_status'])->toBe('refunded')
        ->and((int) DB::table('order_lines')->where('id', $paid['line_id'])->value('cancelled_qty'))->toBe(1)
        ->and((int) DB::table('order_lines')->where('id', $paid['line_id'])->value('held_qty'))->toBe(0)
        ->and((int) DB::table('order_lines')->where('id', $paid['line_id'])->value('eligibility_revision'))->toBe(2)
        ->and((int) DB::table('stock_reservations')->where('intent_id', $paid['order_id'])->value('remaining_qty'))->toBe(0)
        ->and((string) DB::table('stock_reservations')->where('intent_id', $paid['order_id'])->value('status'))->toBe('closed')
        ->and((string) DB::table('coupon_holds')->where('intent_id', $paid['order_id'])->value('status'))->toBe('committed')
        ->and((int) DB::table('refunds')->where('order_id', $paid['order_id'])->where('kind', 'cancellation')->value('amount'))->toBe($paid['amount'])
        ->and(DB::table('outbox_events')->where('event_type', 'checkout.order.cancelled')->where('intent_key', 'order:cancelled:'.$paid['order_id'])->count())->toBe(1);
    $event = DB::table('outbox_events')->where('event_type', 'checkout.order.cancelled')->first();
    $payload = json_decode(Crypt::decryptString((string) $event->payload), true);
    expect($payload['order_id'])->toBe($paid['order_id'])
        ->and($payload['order_number'])->toBe((string) DB::table('orders')->where('id', $paid['order_id'])->value('number'))
        ->and($payload)->not->toHaveKey('email');
    app(ConsumeEvent::class)->consume((string) $event->id, 'checkout.order-cancelled');
    $replay = app(CancelOrder::class)->cancel($owner, $paid['order_id'], [
        'reason' => 'Shopper cancelled before shipment.',
    ], $key);
    $secondKey = app(CancelOrder::class)->cancel($owner, $paid['order_id'], [
        'reason' => 'A second click.',
    ], (string) Str::uuid());
    expect($replay['id'])->toBe($paid['order_id'])
        ->and($replay['status'])->toBe('cancelled')
        ->and($secondKey['status'])->toBe('cancelled')
        ->and(DB::table('refunds')->where('order_id', $paid['order_id'])->count())->toBe(1)
        ->and(DB::table('outbox_events')->where('event_type', 'checkout.order.cancelled')->count())->toBe(1);
    $this->get('/')->assertStatus(503);
});

it('CheckoutOrdersCancellation refuses partially shipped orders and cancels zero-total orders without a provider refund', function (): void {
    $catalog = cancellationCatalog();
    $owner = $catalog['owner'];
    $shipped = cancellationPaidPhysical($catalog['physical_variant'], 'guest-cancel-ship', 'cs_cancel_ship', 'pi_cancel_ship', 2);
    DB::table('order_lines')->where('id', $shipped['line_id'])->update(['shipped_qty' => 1]);
    expect(fn () => app(CancelOrder::class)->cancel($owner, $shipped['order_id'], [
        'reason' => 'Cancel the rest.',
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect((string) DB::table('orders')->where('id', $shipped['order_id'])->value('status'))->toBe('confirmed')
        ->and((int) DB::table('order_lines')->where('id', $shipped['line_id'])->value('cancelled_qty'))->toBe(0)
        ->and(DB::table('refunds')->where('order_id', $shipped['order_id'])->count())->toBe(0)
        ->and(DB::table('outbox_events')->where('event_type', 'checkout.order.cancelled')->count())->toBe(0);
    try {
        app(CancelOrder::class)->cancel($owner, $shipped['order_id'], [
            'reason' => 'Cancel the rest.',
        ], (string) Str::uuid());
    } catch (ValidationException $exception) {
        expect($exception->errors()['order'][0] ?? '')->toContain('return');
    }

    Http::fake();
    $free = cancellationFreeDigital($catalog['digital_variant'], 'guest-cancel-free');
    $cancelled = app(CancelOrder::class)->cancel($owner, $free['order_id'], [
        'reason' => 'Digital access revoked before download.',
    ], (string) Str::uuid());
    expect($cancelled['status'])->toBe('cancelled')
        ->and((int) DB::table('order_lines')->where('id', $free['line_id'])->value('cancelled_qty'))->toBe(1)
        ->and((int) DB::table('order_lines')->where('id', $free['line_id'])->value('eligibility_revision'))->toBe(2)
        ->and((string) DB::table('orders')->where('id', $free['order_id'])->value('download_status'))->toBe('revoked')
        ->and(DB::table('refunds')->where('order_id', $free['order_id'])->count())->toBe(0)
        ->and(DB::table('outbox_events')->where('event_type', 'checkout.order.cancelled')->where('intent_key', 'order:cancelled:'.$free['order_id'])->count())->toBe(1)
        ->and(Http::recorded()->filter(fn (mixed $pair): bool => is_array($pair) && ($pair[0] ?? null) instanceof Request && str_contains($pair[0]->url(), '/v1/refunds'))->count())->toBe(0);
    $this->get('/')->assertStatus(503);
});

it('CheckoutOrdersCancellation HTTP requires manage permission, fresh 2FA, and keeps GET / closed', function (): void {
    $catalog = cancellationCatalog();
    $paid = cancellationPaidPhysical($catalog['physical_variant'], 'guest-cancel-http', 'cs_cancel_http', 'pi_cancel_http');
    $owner = $catalog['owner'];
    fakeStripeCancellationRefund('succeeded', 're_cancel_http', $paid['amount'], 'pi_cancel_http');
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version, 'staff_two_factor_verified' => true])
        ->from('/admin/orders/'.$paid['order_id'])
        ->post('/admin/orders/'.$paid['order_id'].'/cancellation', [
            'reason' => 'Needs reauthentication.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/admin/two-factor');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/orders/'.$paid['order_id'])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('OrderDetail')
        ->where('canCancel', true)
        ->where('order.cancellable', true));
    $this->from('/admin/orders/'.$paid['order_id'])
        ->post('/admin/orders/'.$paid['order_id'].'/cancellation', [
            'reason' => 'Unshipped cancellation.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/admin/orders/'.$paid['order_id']);
    expect((string) DB::table('orders')->where('id', $paid['order_id'])->value('status'))->toBe('cancelled');
    $fulfillment = new StaffAccount;
    $fulfillment->forceFill([
        'name' => 'Shipper', 'email' => 'shipper-cancel@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'fulfillment', 'permissions' => StaffPermissions::forRole('fulfillment'), 'is_active' => true, 'session_version' => 1,
    ])->save();
    expect(fn () => app(CancelOrder::class)->cancel($fulfillment, $paid['order_id'], [
        'reason' => 'No.',
    ], (string) Str::uuid()))->toThrow(AuthorizationException::class);
    $this->actingAs($fulfillment, 'staff')->withSession(catalogStaffSession($fulfillment))
        ->postJson('/admin/orders/'.$paid['order_id'].'/cancellation', [
            'reason' => 'No.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
    $this->get('/')->assertStatus(503);
});
