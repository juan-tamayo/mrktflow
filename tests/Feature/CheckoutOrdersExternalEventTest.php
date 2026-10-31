<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\AcceptProviderEvent;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\ConfirmOrderPayment;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\ReadAdminOrders;
use App\Domain\CheckoutOrders\Actions\RequestRefund;
use App\Domain\CheckoutOrders\Actions\StartPayment;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\PaymentSession;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\StaffAccount;
use App\Jobs\ProcessPaymentEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-16T15:00:00Z')->utc());
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
function extEventCatalog(): array
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

/**
 * @param  array<string, mixed>  $quoteOverrides
 * @return array<string, mixed>
 */
function extEventPaidOrder(string $variantId, string $guestToken, string $sessionId, string $captureId, array $quoteOverrides): array
{
    $guest = CartOwner::guest($guestToken);
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $variantId, 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], $quoteOverrides));
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
                'expires_at' => Carbon::parse('2026-10-16T15:30:00Z')->utc()->getTimestamp(),
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
        Carbon::parse('2026-10-16T15:30:00Z')->utc()->getTimestamp(),
        $captureId,
    ));
    $lineId = (string) DB::table('order_lines')->where('order_id', $intent['order_id'])->value('id');

    return $intent + ['line_id' => $lineId, 'capture_id' => $captureId, 'amount' => $amount];
}

/**
 * @param  array<string, mixed>  $event
 */
function extEventDeliver(array $event): string
{
    $payload = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp = (string) Carbon::now()->timestamp;
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, (string) config('services.stripe.webhook_secret'));
    $id = app(AcceptProviderEvent::class)->accept($payload, $signature, null);
    (new ProcessPaymentEvent($id))->handle();

    return $id;
}

/**
 * @param  array<string, mixed>  $object
 * @return array<string, mixed>
 */
function extEventEnvelope(string $eventId, string $type, array $object): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'livemode' => false,
        'created' => 1760396400,
        'data' => ['object' => $object],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function extEventRefundObject(array $overrides = []): array
{
    return array_replace([
        'id' => 're_external_1',
        'object' => 'refund',
        'amount' => 1000,
        'currency' => 'usd',
        'payment_intent' => 'pi_ext_phys',
        'charge' => 'ch_ext_phys',
        'status' => 'succeeded',
        'reason' => 'requested_by_customer',
        'metadata' => [],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function extEventDisputeObject(array $overrides = []): array
{
    return array_replace([
        'id' => 'dp_ext_1',
        'object' => 'dispute',
        'amount' => 550,
        'currency' => 'usd',
        'charge' => 'ch_ext_dig',
        'payment_intent' => 'pi_ext_dig',
        'status' => 'needs_response',
        'reason' => 'fraudulent',
    ], $overrides);
}

it('CheckoutOrdersExternalEvent records a provider refund once and bounds staff refunds by provider facts', function (): void {
    $catalog = extEventCatalog();
    $paid = extEventPaidOrder($catalog['physical_variant'], 'guest-ext-refund', 'cs_ext_phys', 'pi_ext_phys', ['coupon' => 'SAVE10']);
    $orderId = $paid['order_id'];
    expect($paid['amount'])->toBe(2805);

    $createdId = extEventDeliver(extEventEnvelope('evt_ext_refund_created', 'refund.created', extEventRefundObject(['status' => 'pending'])));
    $refund = DB::table('refunds')->where('order_id', $orderId)->first();
    expect($refund)->not->toBeNull()
        ->and($refund->origin)->toBe('provider')
        ->and($refund->staff_id)->toBeNull()
        ->and($refund->kind)->toBe('external')
        ->and($refund->status)->toBe('processing')
        ->and($refund->amount)->toBe(1000)
        ->and($refund->provider_refund_id)->toBe('re_external_1');
    expect(DB::table('orders')->where('id', $orderId)->value('financial_status'))->toBe('paid');
    expect(DB::table('provider_events')->where('id', $createdId)->value('processing_state'))->toBe('processed');

    extEventDeliver(extEventEnvelope('evt_ext_refund_updated', 'refund.updated', extEventRefundObject()));
    expect(DB::table('refunds')->where('order_id', $orderId)->count())->toBe(1)
        ->and(DB::table('refunds')->where('order_id', $orderId)->value('status'))->toBe('succeeded');
    expect(DB::table('orders')->where('id', $orderId)->value('financial_status'))->toBe('partially_refunded');
    expect(DB::table('order_events')->where('order_id', $orderId)->where('type', 'external_refund')->count())->toBe(1);

    $charge = extEventRefundObject();
    extEventDeliver(extEventEnvelope('evt_ext_charge_refunded', 'charge.refunded', [
        'id' => 'ch_ext_phys',
        'object' => 'charge',
        'amount' => 2805,
        'amount_refunded' => 1000,
        'currency' => 'usd',
        'payment_intent' => 'pi_ext_phys',
        'refunds' => ['object' => 'list', 'data' => [$charge]],
    ]));
    extEventDeliver(extEventEnvelope('evt_ext_refund_updated', 'refund.updated', extEventRefundObject()));
    expect(DB::table('provider_events')->where('event_id', 'evt_ext_refund_updated')->count())->toBe(1)
        ->and(DB::table('refunds')->where('order_id', $orderId)->count())->toBe(1)
        ->and(DB::table('order_events')->where('order_id', $orderId)->where('type', 'external_refund')->count())->toBe(1);

    extEventDeliver(extEventEnvelope('evt_ext_refund2_created', 'refund.created', extEventRefundObject([
        'id' => 're_external_2', 'amount' => 1805, 'status' => 'pending',
    ])));
    extEventDeliver(extEventEnvelope('evt_ext_refund2_updated', 'refund.updated', extEventRefundObject([
        'id' => 're_external_2', 'amount' => 1805,
    ])));
    expect(DB::table('refunds')->where('order_id', $orderId)->count())->toBe(2)
        ->and(DB::table('orders')->where('id', $orderId)->value('financial_status'))->toBe('refunded');

    expect(fn () => app(RequestRefund::class)->request($catalog['owner'], $orderId, [
        'amount' => 1, 'shipping_amount' => 0, 'lines' => [], 'reason' => 'One cent too far.',
    ], (string) Str::uuid()))->toThrow(ValidationException::class);

    $detail = app(ReadAdminOrders::class)->detail($catalog['owner'], $orderId);
    $origins = array_column($detail['refunds'], 'origin');
    expect($origins)->toBe(['provider', 'provider'])
        ->and($detail['dispute_status'])->toBe('none')
        ->and($detail['disputes'])->toBe([]);
});

it('CheckoutOrdersExternalEvent reconciles a staff refund observed by webhook without duplicating it', function (): void {
    $catalog = extEventCatalog();
    $paid = extEventPaidOrder($catalog['physical_variant'], 'guest-ext-staff-obs', 'cs_ext_staff', 'pi_ext_staff', ['coupon' => 'SAVE10']);
    $orderId = $paid['order_id'];
    Http::fake(function (Request $request): mixed {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/refunds')) {
            throw new ConnectionException('timed out after the provider accepted the refund');
        }

        return null;
    });
    $view = app(RequestRefund::class)->request($catalog['owner'], $orderId, [
        'amount' => 0,
        'shipping_amount' => 0,
        'lines' => [['id' => $paid['line_id'], 'quantity' => 1]],
        'reason' => 'Customer asked.',
    ], (string) Str::uuid());
    $refundId = $view['id'];
    expect(DB::table('refunds')->where('id', $refundId)->value('status'))->toBe('unknown')
        ->and(DB::table('order_lines')->where('id', $paid['line_id'])->value('held_qty'))->toBe(1);

    extEventDeliver(extEventEnvelope('evt_ext_staff_observed', 'refund.updated', extEventRefundObject([
        'id' => 're_staff_observed',
        'amount' => 2475,
        'payment_intent' => 'pi_ext_staff',
        'charge' => 'ch_ext_staff',
        'metadata' => ['refund_id' => $refundId],
    ])));
    expect(DB::table('refunds')->where('order_id', $orderId)->count())->toBe(1);
    $refund = DB::table('refunds')->where('id', $refundId)->first();
    expect($refund->status)->toBe('succeeded')
        ->and($refund->origin)->toBe('staff')
        ->and($refund->provider_refund_id)->toBe('re_staff_observed');
    $line = DB::table('order_lines')->where('id', $paid['line_id'])->first();
    expect($line->held_qty)->toBe(0)
        ->and($line->cancelled_qty)->toBe(1)
        ->and($line->refunded_qty)->toBe(1);
    expect(DB::table('orders')->where('id', $orderId)->value('financial_status'))->toBe('partially_refunded');
    expect(DB::table('order_events')->where('order_id', $orderId)->where('type', 'external_refund')->count())->toBe(0);
});

it('CheckoutOrdersExternalEvent ignores unbindable or mismatched provider observations', function (): void {
    $catalog = extEventCatalog();
    $paid = extEventPaidOrder($catalog['physical_variant'], 'guest-ext-mismatch', 'cs_ext_mismatch', 'pi_ext_mismatch', ['coupon' => 'SAVE10']);
    $orderId = $paid['order_id'];

    extEventDeliver(extEventEnvelope('evt_ext_unknown_pi', 'refund.updated', extEventRefundObject([
        'payment_intent' => 'pi_does_not_exist', 'charge' => 'ch_nope',
    ])));
    extEventDeliver(extEventEnvelope('evt_ext_wrong_currency', 'refund.updated', extEventRefundObject([
        'id' => 're_external_eur', 'currency' => 'eur', 'payment_intent' => 'pi_ext_mismatch', 'charge' => 'ch_ext_mismatch',
    ])));
    extEventDeliver(extEventEnvelope('evt_ext_over_capture', 'refund.updated', extEventRefundObject([
        'id' => 're_external_huge', 'amount' => 999999, 'payment_intent' => 'pi_ext_mismatch', 'charge' => 'ch_ext_mismatch',
    ])));
    extEventDeliver(extEventEnvelope('evt_ext_dispute_unknown', 'charge.dispute.created', extEventDisputeObject([
        'payment_intent' => 'pi_does_not_exist', 'charge' => 'ch_nope',
    ])));

    expect(DB::table('refunds')->where('order_id', $orderId)->count())->toBe(0)
        ->and(DB::table('order_disputes')->where('order_id', $orderId)->count())->toBe(0)
        ->and(DB::table('orders')->where('id', $orderId)->value('financial_status'))->toBe('paid')
        ->and(DB::table('orders')->where('id', $orderId)->value('dispute_status'))->toBe('none')
        ->and(DB::table('order_events')->where('order_id', $orderId)->where('type', '!=', 'paid')->count())->toBe(0)
        ->and(DB::table('provider_events')->where('processing_state', 'processed')->count())->toBe(4);
});

it('CheckoutOrdersExternalEvent opens a dispute hold and restores it when the dispute is won', function (): void {
    $catalog = extEventCatalog();
    $paid = extEventPaidOrder($catalog['digital_variant'], 'guest-ext-dispute-win', 'cs_ext_dig', 'pi_ext_dig', ['shipping' => null]);
    $orderId = $paid['order_id'];
    expect($paid['amount'])->toBe(550)
        ->and(DB::table('orders')->where('id', $orderId)->value('download_status'))->toBe('entitled')
        ->and(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('confirmed');

    extEventDeliver(extEventEnvelope('evt_ext_dispute_created', 'charge.dispute.created', extEventDisputeObject()));
    $order = DB::table('orders')->where('id', $orderId)->first();
    expect($order->dispute_status)->toBe('open')
        ->and($order->status)->toBe('on_hold')
        ->and($order->download_status)->toBe('suspended');
    $dispute = DB::table('order_disputes')->where('order_id', $orderId)->first();
    expect($dispute->status)->toBe('opened')
        ->and($dispute->provider_dispute_id)->toBe('dp_ext_1')
        ->and($dispute->amount)->toBe(550)
        ->and((bool) $dispute->held_order)->toBeTrue();
    expect(DB::table('order_events')->where('order_id', $orderId)->where('type', 'dispute_opened')->count())->toBe(1);

    extEventDeliver(extEventEnvelope('evt_ext_dispute_created_again', 'charge.dispute.created', extEventDisputeObject()));
    expect(DB::table('order_disputes')->where('order_id', $orderId)->count())->toBe(1)
        ->and(DB::table('order_events')->where('order_id', $orderId)->where('type', 'dispute_opened')->count())->toBe(1)
        ->and(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('on_hold');

    extEventDeliver(extEventEnvelope('evt_ext_dispute_closed_won', 'charge.dispute.closed', extEventDisputeObject(['status' => 'won'])));
    $order = DB::table('orders')->where('id', $orderId)->first();
    expect($order->dispute_status)->toBe('won')
        ->and($order->status)->toBe('confirmed')
        ->and($order->download_status)->toBe('entitled')
        ->and($order->financial_status)->toBe('paid');
    expect(DB::table('order_disputes')->where('order_id', $orderId)->value('status'))->toBe('won')
        ->and(DB::table('order_events')->where('order_id', $orderId)->where('type', 'dispute_won')->count())->toBe(1)
        ->and(DB::table('refunds')->where('order_id', $orderId)->count())->toBe(0);

    extEventDeliver(extEventEnvelope('evt_ext_dispute_created_late', 'charge.dispute.created', extEventDisputeObject()));
    $order = DB::table('orders')->where('id', $orderId)->first();
    expect($order->dispute_status)->toBe('won')
        ->and($order->status)->toBe('confirmed')
        ->and($order->download_status)->toBe('entitled')
        ->and(DB::table('order_disputes')->where('order_id', $orderId)->value('status'))->toBe('won');

    $detail = app(ReadAdminOrders::class)->detail($catalog['owner'], $orderId);
    expect($detail['dispute_status'])->toBe('won')
        ->and($detail['disputes'])->toHaveCount(1)
        ->and($detail['disputes'][0]['status'])->toBe('won')
        ->and($detail['disputes'][0]['provider_dispute_id'])->toBe('dp_ext_1');
});

it('CheckoutOrdersExternalEvent applies a lost dispute debit once even when closed arrives before created', function (): void {
    $catalog = extEventCatalog();
    $paid = extEventPaidOrder($catalog['digital_variant'], 'guest-ext-dispute-lost', 'cs_ext_lost', 'pi_ext_lost', ['shipping' => null]);
    $orderId = $paid['order_id'];
    $dispute = extEventDisputeObject([
        'id' => 'dp_ext_lost', 'charge' => 'ch_ext_lost', 'payment_intent' => 'pi_ext_lost',
    ]);

    extEventDeliver(extEventEnvelope('evt_ext_lost_closed_first', 'charge.dispute.closed', array_replace($dispute, ['status' => 'lost'])));
    $order = DB::table('orders')->where('id', $orderId)->first();
    expect($order->dispute_status)->toBe('lost')
        ->and($order->download_status)->toBe('revoked')
        ->and($order->financial_status)->toBe('refunded');
    expect(DB::table('order_disputes')->where('order_id', $orderId)->value('status'))->toBe('lost');
    $debits = DB::table('refunds')->where('order_id', $orderId)->get();
    expect($debits)->toHaveCount(1)
        ->and($debits[0]->origin)->toBe('provider')
        ->and($debits[0]->kind)->toBe('external')
        ->and($debits[0]->status)->toBe('succeeded')
        ->and($debits[0]->amount)->toBe(550);
    expect(DB::table('order_events')->where('order_id', $orderId)->where('type', 'dispute_lost')->count())->toBe(1);

    extEventDeliver(extEventEnvelope('evt_ext_lost_created_late', 'charge.dispute.created', $dispute));
    extEventDeliver(extEventEnvelope('evt_ext_lost_closed_replay', 'charge.dispute.closed', array_replace($dispute, ['status' => 'lost'])));
    $order = DB::table('orders')->where('id', $orderId)->first();
    expect($order->dispute_status)->toBe('lost')
        ->and($order->download_status)->toBe('revoked')
        ->and(DB::table('refunds')->where('order_id', $orderId)->count())->toBe(1)
        ->and(DB::table('order_events')->where('order_id', $orderId)->where('type', 'dispute_lost')->count())->toBe(1);

    expect(fn () => app(RequestRefund::class)->request($catalog['owner'], $orderId, [
        'amount' => 1, 'shipping_amount' => 0, 'lines' => [], 'reason' => 'Nothing left.',
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
});

it('CheckoutOrdersExternalEvent does not restore download access on a won dispute after a full provider refund', function (): void {
    $catalog = extEventCatalog();
    $paid = extEventPaidOrder($catalog['digital_variant'], 'guest-ext-dispute-refunded', 'cs_ext_mixed', 'pi_ext_mixed', ['shipping' => null]);
    $orderId = $paid['order_id'];
    $dispute = extEventDisputeObject([
        'id' => 'dp_ext_mixed', 'charge' => 'ch_ext_mixed', 'payment_intent' => 'pi_ext_mixed',
    ]);

    extEventDeliver(extEventEnvelope('evt_ext_mixed_created', 'charge.dispute.created', $dispute));
    expect(DB::table('orders')->where('id', $orderId)->value('download_status'))->toBe('suspended');

    extEventDeliver(extEventEnvelope('evt_ext_mixed_refund', 'refund.updated', extEventRefundObject([
        'id' => 're_external_full', 'amount' => 550, 'payment_intent' => 'pi_ext_mixed', 'charge' => 'ch_ext_mixed',
    ])));
    expect(DB::table('orders')->where('id', $orderId)->value('financial_status'))->toBe('refunded');

    extEventDeliver(extEventEnvelope('evt_ext_mixed_won', 'charge.dispute.closed', array_replace($dispute, ['status' => 'won'])));
    $order = DB::table('orders')->where('id', $orderId)->first();
    expect($order->dispute_status)->toBe('won')
        ->and($order->status)->toBe('confirmed')
        ->and($order->download_status)->toBe('suspended')
        ->and($order->financial_status)->toBe('refunded');
});
