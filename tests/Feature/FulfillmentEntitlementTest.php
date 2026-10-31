<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\ConfirmOrderPayment;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\ReconcileExternalAdjustment;
use App\Domain\CheckoutOrders\Actions\RequestRefund;
use App\Domain\CheckoutOrders\Actions\StartPayment;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Data\DisputeObservation;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\PaymentSession;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Fulfillment\Actions\IssueEntitlements;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\ConsumeEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-16T12:00:00Z')->utc());
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
 * @return array{owner: StaffAccount, lamp: string, guide: string, guide_product: string, version_id: string}
 */
function entitlementCatalog(): array
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
    $lamp = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'price_amount' => 2500,
    ]));
    $lampVariant = (string) DB::table('product_variants')->where('product_id', $lamp['id'])->value('id');
    app(AdjustStock::class)->adjust($owner, [
        'variant_id' => $lampVariant,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 10,
        'low_stock_threshold' => 1,
    ]);
    $guide = app(SaveProduct::class)->save($owner, catalogDigitalProductInput($image, [
        'status' => 'published', 'price_amount' => 500,
    ]));
    $guideVariant = (string) DB::table('product_variants')->where('product_id', $guide['id'])->value('id');
    $ready = app(VersionDigitalAsset::class)->version($owner, [
        'product_id' => $guide['id'],
        'label' => 'Lighting guide',
        'file' => catalogPrivateTextFile(),
    ]);
    app(VersionDigitalAsset::class)->attach($owner, [
        'product_id' => $guide['id'],
        'variant_id' => $guideVariant,
        'version_ids' => [$ready['id']],
    ]);

    return [
        'owner' => $owner,
        'lamp' => $lampVariant,
        'guide' => $guideVariant,
        'guide_product' => $guide['id'],
        'version_id' => $ready['id'],
    ];
}

/**
 * @param  list<array{variant: string, quantity: int}>  $lines
 * @return array<string, mixed>
 */
function entitlementIntent(array $lines, string $guestToken): array
{
    $guest = CartOwner::guest($guestToken);
    $revision = 1;
    $cart = null;
    foreach ($lines as $line) {
        $cart = app(UpdateCart::class)->replaceLine($guest, [
            'variant_id' => $line['variant'], 'quantity' => $line['quantity'], 'expected_revision' => $revision,
        ]);
        $revision = $cart['revision'];
    }
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision']));
    app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], (string) Str::uuid());
    $lineIds = [];
    foreach (DB::table('order_lines')->where('order_id', $intent['order_id'])->get() as $row) {
        $lineIds[(string) $row->variant_id] = (string) $row->id;
    }

    return $intent + ['line_ids' => $lineIds];
}

/**
 * @param  list<array{variant: string, quantity: int}>  $lines
 * @return array<string, mixed>
 */
function entitlementPaidOrder(array $lines, string $guestToken, string $sessionId, string $captureId): array
{
    $intent = entitlementIntent($lines, $guestToken);
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
                'expires_at' => Carbon::parse('2026-10-16T12:30:00Z')->utc()->getTimestamp(),
                'url' => 'https://checkout.stripe.com/c/pay/'.$sessionId,
                'payment_intent' => null,
                'livemode' => false,
                'payment_method_types' => ['card'],
            ], 200);
        }

        return null;
    });
    $guest = CartOwner::guest($guestToken);
    app(StartPayment::class)->start($guest, $intent['id']);
    $payment = new PaymentIntent($intent['id'], $amount, 'USD', 'acct_fixture', false);
    app(ConfirmOrderPayment::class)->confirm($intent['id'], PaymentSession::observed(
        $payment,
        $payment,
        $sessionId,
        'complete',
        'paid',
        Carbon::parse('2026-10-16T12:30:00Z')->utc()->getTimestamp(),
        $captureId,
    ));
    $paidEvent = DB::table('outbox_events')->where('event_type', 'checkout.order.paid')
        ->where('intent_key', 'order:paid:'.$intent['order_id'])->first();
    expect($paidEvent)->not->toBeNull();

    return $intent + ['event_id' => (string) $paidEvent->id, 'amount' => $amount, 'capture_id' => $captureId];
}

function entitlementConsume(array $paid): void
{
    app(ConsumeEvent::class)->consume($paid['event_id'], 'fulfillment.entitlements');
}

function entitlementFakeRefundSucceeded(): void
{
    Http::fake(function (Request $request): mixed {
        $posted = $request->data();
        $meta = is_array($posted['metadata'] ?? null) ? $posted['metadata'] : [];
        $boundId = is_string($meta['refund_id'] ?? null) ? (string) $meta['refund_id'] : '';
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/refunds')) {
            return Http::response([
                'id' => 're_ent_'.str_replace('-', '', $boundId),
                'object' => 'refund',
                'amount' => is_numeric($posted['amount'] ?? null) ? (int) $posted['amount'] : 0,
                'payment_intent' => is_string($posted['payment_intent'] ?? null) ? $posted['payment_intent'] : '',
                'status' => 'succeeded',
                'metadata' => $boundId === '' ? [] : ['refund_id' => $boundId],
            ], 200);
        }

        return null;
    });
}

it('FulfillmentEntitlement issues one active version-pinned entitlement for a paid digital line and replays safely', function (): void {
    $catalog = entitlementCatalog();
    $paid = entitlementPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], 'guest-ent-paid', 'cs_ent_paid', 'pi_ent_paid');
    $orderId = $paid['order_id'];
    $lineId = $paid['line_ids'][$catalog['guide']];
    expect(DB::table('consumer_receipts')->where('event_id', $paid['event_id'])
        ->where('consumer', 'fulfillment.entitlements')->value('status'))->toBe('pending');

    entitlementConsume($paid);
    $rows = DB::table('entitlements')->where('order_id', $orderId)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->order_line_id)->toBe($lineId)
        ->and($rows[0]->digital_asset_version_id)->toBe($catalog['version_id'])
        ->and($rows[0]->status)->toBe('active')
        ->and($rows[0]->customer_id)->toBeNull()
        ->and($rows[0]->source_event_id)->toBe($paid['event_id']);
    expect(DB::table('order_events')->where('order_id', $orderId)->where('type', 'entitlements_issued')->count())->toBe(1)
        ->and(DB::table('orders')->where('id', $orderId)->value('download_status'))->toBe('entitled');

    // A newer asset version attached after purchase must not move the pin.
    $v2 = app(VersionDigitalAsset::class)->version($catalog['owner'], [
        'product_id' => $catalog['guide_product'],
        'digital_asset_id' => (string) DB::table('digital_asset_versions')->where('id', $catalog['version_id'])->value('digital_asset_id'),
        'label' => 'Lighting guide',
        'file' => catalogPrivateTextFile(),
    ]);
    app(VersionDigitalAsset::class)->attach($catalog['owner'], [
        'product_id' => $catalog['guide_product'],
        'variant_id' => $catalog['guide'],
        'version_ids' => [$v2['id']],
    ]);

    // Redelivery: completing the receipt again and forcing a receipt replay change nothing.
    entitlementConsume($paid);
    DB::table('consumer_receipts')->where('event_id', $paid['event_id'])
        ->where('consumer', 'fulfillment.entitlements')->update(['status' => 'pending', 'available_at' => now()]);
    entitlementConsume($paid);
    $again = app(IssueEntitlements::class)->issueForOrder($orderId, $paid['event_id']);
    expect($again)->toHaveCount(1)
        ->and($again[0]['created'])->toBeFalse()
        ->and(DB::table('entitlements')->where('order_id', $orderId)->count())->toBe(1)
        ->and(DB::table('entitlements')->where('order_id', $orderId)->value('digital_asset_version_id'))->toBe($catalog['version_id'])
        ->and(DB::table('entitlements')->where('order_id', $orderId)->value('status'))->toBe('active')
        ->and(DB::table('order_events')->where('order_id', $orderId)->where('type', 'entitlements_issued')->count())->toBe(1);
});

it('FulfillmentEntitlement issues only digital lines on a mixed order and none for a physical-only order', function (): void {
    $catalog = entitlementCatalog();
    $mixed = entitlementPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 2],
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], 'guest-ent-mixed', 'cs_ent_mixed', 'pi_ent_mixed');
    entitlementConsume($mixed);
    $rows = DB::table('entitlements')->where('order_id', $mixed['order_id'])->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->order_line_id)->toBe($mixed['line_ids'][$catalog['guide']])
        ->and($rows[0]->status)->toBe('active');
    expect(DB::table('orders')->where('id', $mixed['order_id'])->value('fulfillment_status'))->toBe('unfulfilled');

    $physical = entitlementPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 1],
    ], 'guest-ent-physical', 'cs_ent_phys', 'pi_ent_phys');
    entitlementConsume($physical);
    expect(DB::table('entitlements')->where('order_id', $physical['order_id'])->count())->toBe(0)
        ->and(DB::table('order_events')->where('order_id', $physical['order_id'])->where('type', 'entitlements_issued')->count())->toBe(0)
        ->and(DB::table('consumer_receipts')->where('event_id', $physical['event_id'])
            ->where('consumer', 'fulfillment.entitlements')->value('status'))->toBe('complete');
});

it('FulfillmentEntitlement refuses issuance without a recorded payment fact', function (): void {
    $catalog = entitlementCatalog();
    $intent = entitlementIntent([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], 'guest-ent-unpaid');
    expect(DB::table('payments')->where('order_id', $intent['order_id'])->count())->toBe(0);
    expect(fn () => app(IssueEntitlements::class)->issueForOrder($intent['order_id'], null))
        ->toThrow(InvalidArgumentException::class);
    expect(DB::table('entitlements')->where('order_id', $intent['order_id'])->count())->toBe(0);
});

it('FulfillmentEntitlement issues for a free-completed customer order with the customer as grantee', function (): void {
    $catalog = entitlementCatalog();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'free-ent@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true, 'session_version' => 1, 'email_verified_at' => now(),
    ])->save();
    $owner = CartOwner::customer($customer->id);
    $cart = app(UpdateCart::class)->replaceLine($owner, [
        'variant_id' => $catalog['guide'], 'quantity' => 1, 'expected_revision' => 1,
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
    app(ConsumeEvent::class)->consume((string) $paidEvent->id, 'fulfillment.entitlements');
    $row = DB::table('entitlements')->where('order_id', $intent['order_id'])->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('active')
        ->and($row->customer_id)->toBe($customer->id)
        ->and($row->digital_asset_version_id)->toBe($catalog['version_id']);
});

it('FulfillmentEntitlement records a revoked tombstone when the digital line was fully refunded before issuance', function (): void {
    $catalog = entitlementCatalog();
    $paid = entitlementPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], 'guest-ent-refunded', 'cs_ent_ref', 'pi_ent_ref');
    $orderId = $paid['order_id'];
    $lineId = $paid['line_ids'][$catalog['guide']];
    entitlementFakeRefundSucceeded();
    $refund = app(RequestRefund::class)->request($catalog['owner'], $orderId, [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Customer changed their mind.',
    ], (string) Str::uuid());
    expect($refund['status'])->toBe('succeeded')
        ->and((int) DB::table('order_lines')->where('id', $lineId)->value('refunded_qty'))->toBe(1);

    entitlementConsume($paid);
    $row = DB::table('entitlements')->where('order_id', $orderId)->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('revoked')
        ->and($row->order_line_id)->toBe($lineId);
    // A later replay of the stale paid event can never upgrade the tombstone.
    $again = app(IssueEntitlements::class)->issueForOrder($orderId, $paid['event_id']);
    expect($again[0]['status'])->toBe('revoked')
        ->and($again[0]['created'])->toBeFalse()
        ->and(DB::table('entitlements')->where('order_id', $orderId)->count())->toBe(1);
});

it('FulfillmentEntitlement retains access after an amount-only goodwill refund', function (): void {
    $catalog = entitlementCatalog();
    $paid = entitlementPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], 'guest-ent-goodwill', 'cs_ent_good', 'pi_ent_good');
    entitlementConsume($paid);
    expect(DB::table('entitlements')->where('order_id', $paid['order_id'])->value('status'))->toBe('active');
    entitlementFakeRefundSucceeded();
    $refund = app(RequestRefund::class)->request($catalog['owner'], $paid['order_id'], [
        'lines' => [],
        'amount' => 100,
        'shipping_amount' => 0,
        'reason' => 'Goodwill adjustment.',
    ], (string) Str::uuid());
    expect($refund['status'])->toBe('succeeded')->and($refund['kind'])->toBe('goodwill');
    $again = app(IssueEntitlements::class)->issueForOrder($paid['order_id'], $paid['event_id']);
    expect($again[0]['status'])->toBe('active')
        ->and(DB::table('entitlements')->where('order_id', $paid['order_id'])->count())->toBe(1);
});

it('FulfillmentEntitlement issues suspended under an open dispute and never un-suspends the order', function (): void {
    $catalog = entitlementCatalog();
    $paid = entitlementPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], 'guest-ent-dispute', 'cs_ent_dis', 'pi_ent_dis');
    $orderId = $paid['order_id'];
    app(ReconcileExternalAdjustment::class)->reconcileDispute(new DisputeObservation(
        'dp_ent_1', $paid['capture_id'], $paid['amount'], 'USD', 'opened', 'fraudulent',
    ), (string) Str::uuid());
    expect(DB::table('orders')->where('id', $orderId)->value('download_status'))->toBe('suspended')
        ->and(DB::table('orders')->where('id', $orderId)->value('dispute_status'))->toBe('open');

    entitlementConsume($paid);
    $row = DB::table('entitlements')->where('order_id', $orderId)->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('suspended')
        ->and(DB::table('orders')->where('id', $orderId)->value('download_status'))->toBe('suspended');
});

it('FulfillmentEntitlement records a revoked tombstone when a dispute was lost before issuance', function (): void {
    $catalog = entitlementCatalog();
    $paid = entitlementPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], 'guest-ent-lost', 'cs_ent_lost', 'pi_ent_lost');
    $orderId = $paid['order_id'];
    app(ReconcileExternalAdjustment::class)->reconcileDispute(new DisputeObservation(
        'dp_ent_lost', $paid['capture_id'], $paid['amount'], 'USD', 'lost', 'fraudulent',
    ), (string) Str::uuid());
    expect(DB::table('orders')->where('id', $orderId)->value('download_status'))->toBe('revoked');

    entitlementConsume($paid);
    $row = DB::table('entitlements')->where('order_id', $orderId)->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('revoked');
});
