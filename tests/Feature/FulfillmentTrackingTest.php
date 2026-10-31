<?php

declare(strict_types=1);

use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\ConfirmOrderPayment;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\ReadCustomerOrders;
use App\Domain\CheckoutOrders\Actions\StartPayment;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\PaymentSession;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Fulfillment\Actions\CreateShipment;
use App\Domain\Fulfillment\Actions\QueueShipmentNotification;
use App\Domain\Fulfillment\Rules\TrackingUrl;
use App\Domain\Identity\Models\CustomerAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-16T16:00:00Z')->utc());
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

function trackingCustomer(string $email): CustomerAccount
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
 * @param  list<array{variant: string, quantity: int}>  $lines
 * @return array<string, mixed>
 */
function trackingIntent(array $lines, CartOwner $owner, string $email): array
{
    $revision = 1;
    $cart = null;
    foreach ($lines as $line) {
        $cart = app(UpdateCart::class)->replaceLine($owner, [
            'variant_id' => $line['variant'], 'quantity' => $line['quantity'], 'expected_revision' => $revision,
        ]);
        $revision = $cart['revision'];
    }
    $quote = app(CreateQuote::class)->create($owner, checkoutQuoteInput($cart['revision'], ['email' => $email]));
    app(AcceptQuote::class)->accept($owner, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($owner, $quote['id'], (string) Str::uuid());
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
function trackingPaidOrder(array $lines, CartOwner $owner, string $email, string $sessionId, string $captureId): array
{
    $intent = trackingIntent($lines, $owner, $email);
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
    app(StartPayment::class)->start($owner, $intent['id']);
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

    return $intent;
}

it('FulfillmentTracking rejects malicious tracking URLs and persists nothing', function (): void {
    $catalog = shipmentCatalog();
    $paid = shipmentPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 2],
    ], 'guest-track-reject', 'cs_track_reject', 'pi_track_reject');
    $orderId = $paid['order_id'];
    $lineId = $paid['line_ids'][$catalog['lamp']];
    $malicious = [
        'javascript:alert(1)',
        'JaVaScRiPt:alert(document.cookie)',
        'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
        'vbscript:msgbox(1)',
        'ftp://carrier.example/track/1',
        '//carrier.example/track/1',
        'carrier.example/track/1',
        'https://user:secret@carrier.example/track/1',
        'https://carrier.example\@evil.example/track/1',
        'https://carrier.example/tra ck/1',
        "https://carrier.example/track/\t1",
        'http://',
        'https://localhost/track/1',
        'https://127.0.0.1/track/1',
        'https://carrier.example/"onclick="alert(1)',
        'https://carrier.example/track/<script>',
    ];
    foreach ($malicious as $url) {
        expect(fn () => app(CreateShipment::class)->create($catalog['owner'], $orderId, [
            'tracking_url' => $url,
            'lines' => [['id' => $lineId, 'quantity' => 1]],
        ], (string) Str::uuid()))->toThrow(ValidationException::class);
        expect(Validator::make(['tracking_url' => $url], ['tracking_url' => [new TrackingUrl]])->fails())
            ->toBeTrue();
    }
    expect(DB::table('shipments')->where('order_id', $orderId)->count())->toBe(0)
        ->and(DB::table('shipment_notification_intents')->where('order_id', $orderId)->count())->toBe(0)
        ->and(DB::table('order_events')->where('order_id', $orderId)->where('type', 'shipment_created')->count())->toBe(0)
        ->and(DB::table('order_lines')->where('id', $lineId)->value('shipped_qty'))->toBe(0);

    $valid = 'https://www.ups.com/track?tracknum=1Z999AA10123456784';
    expect(Validator::make(['tracking_url' => $valid], ['tracking_url' => [new TrackingUrl]])->passes())->toBeTrue();
    $view = app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456784',
        'tracking_url' => $valid,
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());
    expect($view['tracking_url'])->toBe($valid)
        ->and(DB::table('shipments')->where('id', $view['id'])->value('tracking_url'))->toBe($valid);
});

it('FulfillmentTracking HTTP rejects a malicious tracking URL before any shipment is recorded', function (): void {
    $catalog = shipmentCatalog();
    $paid = shipmentPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 1],
    ], 'guest-track-http', 'cs_track_http', 'pi_track_http');
    $orderId = $paid['order_id'];
    $lineId = $paid['line_ids'][$catalog['lamp']];
    $owner = $catalog['owner'];

    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->from('/admin/orders/'.$orderId)
        ->post('/admin/orders/'.$orderId.'/shipments', [
            'carrier' => 'UPS',
            'tracking_url' => 'javascript:alert(document.cookie)',
            'lines' => [['id' => $lineId, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/admin/orders/'.$orderId)->assertSessionHasErrors('tracking_url');
    expect(DB::table('shipments')->where('order_id', $orderId)->count())->toBe(0);

    $this->from('/admin/orders/'.$orderId)
        ->post('/admin/orders/'.$orderId.'/shipments', [
            'carrier' => 'UPS',
            'tracking_number' => '1Z999AA10123456784',
            'tracking_url' => 'https://www.ups.com/track?tracknum=1Z999AA10123456784',
            'lines' => [['id' => $lineId, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/admin/orders/'.$orderId)->assertSessionHas('order_shipment_saved', true);
    expect(DB::table('shipments')->where('order_id', $orderId)->count())->toBe(1)
        ->and(DB::table('shipment_notification_intents')->where('order_id', $orderId)->count())->toBe(1);
    $this->get('/')->assertOk();
});

it('FulfillmentTracking queues one deduplicated notification intent per shipment', function (): void {
    $catalog = shipmentCatalog();
    $paid = shipmentPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 2],
    ], 'guest-track-intent', 'cs_track_intent', 'pi_track_intent');
    $orderId = $paid['order_id'];
    $lineId = $paid['line_ids'][$catalog['lamp']];
    $key = (string) Str::uuid();
    $input = [
        'carrier' => 'FedEx',
        'tracking_number' => '794600000000',
        'tracking_url' => 'https://www.fedex.com/fedextrack/?trknbr=794600000000',
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ];
    $view = app(CreateShipment::class)->create($catalog['owner'], $orderId, $input, $key);
    $intents = DB::table('shipment_notification_intents')->where('order_id', $orderId)->get();
    expect($intents)->toHaveCount(1);
    $intent = $intents->first();
    expect($intent->shipment_id)->toBe($view['id'])
        ->and($intent->template)->toBe('shipment')
        ->and($intent->kind)->toBe('event')
        ->and($intent->status)->toBe('queued')
        ->and($intent->recipient)->toBe('guest@example.test')
        ->and($intent->intent_key)->toBe('shipment:'.$view['id']);
    expect(DB::table('order_events')->where('order_id', $orderId)->where('type', 'shipment_created')->count())->toBe(1)
        ->and(DB::table('order_events')->where('order_id', $orderId)->where('type', 'shipment_notification_queued')->count())->toBe(1);
    $queued = DB::table('order_events')->where('order_id', $orderId)->where('type', 'shipment_notification_queued')->first();
    $payload = json_decode((string) $queued->payload, true);
    expect($payload['shipment_id'])->toBe($view['id'])->and($payload['intent_id'])->toBe($intent->id);
    Mail::assertNothingOutgoing();

    $replay = app(CreateShipment::class)->create($catalog['owner'], $orderId, $input, $key);
    expect($replay['id'])->toBe($view['id']);
    $again = app(QueueShipmentNotification::class)->queue($view['id']);
    $twice = app(QueueShipmentNotification::class)->queue($view['id']);
    expect($again['id'])->toBe($intent->id)->and($twice['id'])->toBe($intent->id);
    expect(DB::table('shipment_notification_intents')->where('order_id', $orderId)->count())->toBe(1)
        ->and(DB::table('order_events')->where('order_id', $orderId)->where('type', 'shipment_notification_queued')->count())->toBe(1);

    $second = app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());
    expect($second['id'])->not->toBe($view['id']);
    expect(DB::table('shipment_notification_intents')->where('order_id', $orderId)->count())->toBe(2)
        ->and(DB::table('shipment_notification_intents')->where('shipment_id', $second['id'])->value('intent_key'))
        ->toBe('shipment:'.$second['id']);
});

it('FulfillmentTracking partial shipment email shows only the quantities in that shipment', function (): void {
    $catalog = shipmentCatalog();
    $customer = trackingCustomer('buyer-track@example.test');
    $paid = trackingPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 3],
        ['variant' => $catalog['mug'], 'quantity' => 2],
    ], CartOwner::customer($customer->id), $customer->email, 'cs_track_email', 'pi_track_email');
    $orderId = $paid['order_id'];
    $lampLine = $paid['line_ids'][$catalog['lamp']];
    $mugLine = $paid['line_ids'][$catalog['mug']];
    $titles = DB::table('order_lines')->where('order_id', $orderId)->pluck('title', 'id');

    $first = app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456784',
        'tracking_url' => 'https://www.ups.com/track?tracknum=1Z999AA10123456784',
        'lines' => [['id' => $lampLine, 'quantity' => 2]],
    ], (string) Str::uuid());
    $lampRow = DB::table('order_lines')->where('id', $lampLine)->first();
    $detail = app(ReadCustomerOrders::class)->detail($customer, $orderId);
    $shipment = collect($detail['shipments'])->firstWhere('id', $first['id']);
    expect($shipment)->not->toBeNull()
        ->and($shipment['lines'])->toBe([['title' => $titles[$lampLine], 'sku' => (string) $lampRow->sku, 'quantity' => 2]]);
    $html = view('mail.shipment', ['order' => $detail, 'shipment' => $shipment])->render();
    expect($html)->toContain($detail['number'])
        ->toContain('UPS')
        ->toContain('1Z999AA10123456784')
        ->toContain('https://www.ups.com/track?tracknum=1Z999AA10123456784')
        ->toContain($titles[$lampLine])
        ->toContain('qty 2')
        ->not->toContain('qty 3')
        ->not->toContain($titles[$mugLine]);

    $second = app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'lines' => [
            ['id' => $lampLine, 'quantity' => 1],
            ['id' => $mugLine, 'quantity' => 2],
        ],
    ], (string) Str::uuid());
    $detail = app(ReadCustomerOrders::class)->detail($customer, $orderId);
    $shipment = collect($detail['shipments'])->firstWhere('id', $second['id']);
    $html = view('mail.shipment', ['order' => $detail, 'shipment' => $shipment])->render();
    expect($html)->toContain($titles[$lampLine])
        ->toContain('qty 1')
        ->toContain($titles[$mugLine])
        ->toContain('qty 2')
        ->not->toContain('Track your shipment');
});

it('FulfillmentTracking customer order page shows the tracking link and never renders a legacy malicious URL', function (): void {
    $catalog = shipmentCatalog();
    $customer = trackingCustomer('owner-track@example.test');
    $stranger = trackingCustomer('stranger-track@example.test');
    $paid = trackingPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 2],
    ], CartOwner::customer($customer->id), $customer->email, 'cs_track_view', 'pi_track_view');
    $orderId = $paid['order_id'];
    $lineId = $paid['line_ids'][$catalog['lamp']];
    $url = 'https://www.ups.com/track?tracknum=1Z999AA10123456784';
    app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456784',
        'tracking_url' => $url,
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());

    $legacyId = (string) Str::uuid();
    DB::table('shipments')->insert([
        'id' => $legacyId,
        'order_id' => $orderId,
        'staff_id' => $catalog['owner']->id,
        'carrier' => 'LegacyPost',
        'tracking_number' => 'LEGACY1',
        'tracking_url' => 'javascript:alert(document.cookie)',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('shipment_lines')->insert([
        'id' => (string) Str::uuid(),
        'shipment_id' => $legacyId,
        'order_line_id' => $lineId,
        'quantity' => 1,
    ]);

    $detail = app(ReadCustomerOrders::class)->detail($customer, $orderId);
    expect($detail['shipments'])->toHaveCount(2);
    $tracked = collect($detail['shipments'])->firstWhere('carrier', 'UPS');
    $legacy = collect($detail['shipments'])->firstWhere('carrier', 'LegacyPost');
    expect($tracked['tracking_url'])->toBe($url)
        ->and($tracked['tracking_number'])->toBe('1Z999AA10123456784')
        ->and($tracked['lines'][0]['quantity'])->toBe(1)
        ->and($legacy['tracking_url'])->toBeNull()
        ->and($legacy['tracking_number'])->toBe('LEGACY1');
    expect(fn () => app(ReadCustomerOrders::class)->detail($stranger, $orderId))
        ->toThrow(NotFoundHttpException::class);

    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/orders/'.$orderId)->assertOk()
        ->assertSee($url)
        ->assertSee('Track this shipment')
        ->assertSee('LEGACY1')
        ->assertDontSee('javascript:');
    $this->actingAs($stranger, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/orders/'.$orderId)->assertNotFound();
});

it('FulfillmentTracking TrackingUrl accepts ordinary carrier URLs and rejects edge forms', function (): void {
    $valid = [
        'https://www.fedex.com/fedextrack/?trknbr=794600000000',
        'http://tracking.dhl.de/en.html',
        'https://tools.usps.com/go/TrackConfirmAction?tLabels=9400110200000000000000',
        'https://carrier.example:8443/track/1',
    ];
    foreach ($valid as $url) {
        expect(TrackingUrl::sanitize($url))->toBe($url)
            ->and(TrackingUrl::normalize($url))->toBe($url);
    }
    expect(TrackingUrl::sanitize(null))->toBeNull()
        ->and(TrackingUrl::sanitize(''))->toBeNull()
        ->and(TrackingUrl::sanitize('   '))->toBeNull()
        ->and(TrackingUrl::normalize(null))->toBeNull()
        ->and(TrackingUrl::normalize(''))->toBeNull();
    $invalid = [
        'https://carrier.example:99999/track',
        'https://carrier.example:0/track',
        'https://carrier..example/track',
        'https://-carrier.example/track',
        'https://carrier.example-/track',
        'https://carrier.123/track',
        'https://user@carrier.example/track',
        'mailto:someone@carrier.example',
    ];
    foreach ($invalid as $url) {
        expect(TrackingUrl::sanitize($url))->toBeNull();
        expect(fn () => TrackingUrl::normalize($url))->toThrow(ValidationException::class);
    }
    expect(fn () => TrackingUrl::normalize(['not-a-string']))->toThrow(ValidationException::class);
});
