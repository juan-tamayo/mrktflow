<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
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
use App\Domain\Fulfillment\Actions\CreateShipment;
use App\Domain\Fulfillment\Exceptions\FulfillmentIdempotencyConflict;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Process\Process;

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
 * @return array{owner: StaffAccount, lamp: string, mug: string, poster: string, guide: string}
 */
function shipmentCatalog(): array
{
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['amount' => 300])]])],
    ]);
    pricingSavedTaxRules($owner);
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
    $mug = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'title' => 'Studio Mug', 'slug' => 'studio-mug', 'sku' => 'MUG-001',
        'status' => 'published', 'price_amount' => 1000,
    ]));
    $mugVariant = (string) DB::table('product_variants')->where('product_id', $mug['id'])->value('id');
    app(AdjustStock::class)->adjust($owner, [
        'variant_id' => $mugVariant,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 5,
        'low_stock_threshold' => 1,
    ]);
    $poster = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'title' => 'Studio Poster', 'slug' => 'studio-poster', 'sku' => 'POSTER-001',
        'status' => 'published', 'price_amount' => 1500,
    ]));
    $posterVariant = (string) DB::table('product_variants')->where('product_id', $poster['id'])->value('id');
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
        'mug' => $mugVariant,
        'poster' => $posterVariant,
        'guide' => $guideVariant,
    ];
}

/**
 * @param  list<array{variant: string, quantity: int}>  $lines
 * @return array<string, mixed>
 */
function shipmentIntent(array $lines, string $guestToken): array
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
function shipmentPaidOrder(array $lines, string $guestToken, string $sessionId, string $captureId): array
{
    $intent = shipmentIntent($lines, $guestToken);
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
    $guest = CartOwner::guest($guestToken);
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

    return $intent;
}

function shipmentStaff(string $role, string $email): StaffAccount
{
    $staff = new StaffAccount;
    $staff->forceFill([
        'name' => 'Staff '.$role, 'email' => $email,
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => $role, 'permissions' => StaffPermissions::forRole($role), 'is_active' => true, 'session_version' => 1,
    ])->save();

    return $staff;
}

it('FulfillmentShipment ships part of a paid order atomically and projects partial then full fulfillment', function (): void {
    $catalog = shipmentCatalog();
    $paid = shipmentPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 3],
    ], 'guest-ship-partial', 'cs_ship_partial', 'pi_ship_partial');
    $orderId = $paid['order_id'];
    $lineId = $paid['line_ids'][$catalog['lamp']];
    $balance = DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->first();
    expect($balance->on_hand)->toBe(10)->and($balance->reserved)->toBe(3);
    $reservation = DB::table('stock_reservations')->where('intent_id', $orderId)->where('variant_id', $catalog['lamp'])->first();
    expect($reservation->status)->toBe('committed')->and($reservation->remaining_qty)->toBe(3);

    $view = app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456784',
        'tracking_url' => null,
        'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid());
    expect($view['order_id'])->toBe($orderId)
        ->and($view['carrier'])->toBe('UPS')
        ->and($view['lines'])->toBe([['id' => $lineId, 'quantity' => 2]]);
    $shipment = DB::table('shipments')->where('id', $view['id'])->first();
    expect($shipment)->not->toBeNull()
        ->and($shipment->staff_id)->toBe($catalog['owner']->id)
        ->and($shipment->tracking_number)->toBe('1Z999AA10123456784');
    expect(DB::table('shipment_lines')->where('shipment_id', $view['id'])->value('quantity'))->toBe(2);
    expect(DB::table('order_lines')->where('id', $lineId)->value('shipped_qty'))->toBe(2);
    expect(DB::table('orders')->where('id', $orderId)->value('fulfillment_status'))->toBe('partially_fulfilled');
    $reservation = DB::table('stock_reservations')->where('intent_id', $orderId)->where('variant_id', $catalog['lamp'])->first();
    expect($reservation->shipped_qty)->toBe(2)->and($reservation->remaining_qty)->toBe(1)->and($reservation->status)->toBe('committed');
    $balance = DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->first();
    expect($balance->on_hand)->toBe(8)->and($balance->reserved)->toBe(1);
    $movement = DB::table('stock_movements')->where('variant_id', $catalog['lamp'])->where('reason', 'ship')->first();
    expect($movement)->not->toBeNull()->and($movement->on_hand_delta)->toBe(-2)->and($movement->reserved_delta)->toBe(-2);
    expect(DB::table('order_events')->where('order_id', $orderId)->where('type', 'shipment_created')->count())->toBe(1);
    $audit = DB::table('outbox_events')->where('intent_key', 'audit:order-shipment:'.$view['id'])->first();
    expect($audit)->not->toBeNull()->and($audit->event_type)->toBe('platform.audit.recorded');

    $second = app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());
    expect($second['id'])->not->toBe($view['id']);
    expect(DB::table('orders')->where('id', $orderId)->value('fulfillment_status'))->toBe('fulfilled');
    $reservation = DB::table('stock_reservations')->where('intent_id', $orderId)->where('variant_id', $catalog['lamp'])->first();
    expect($reservation->remaining_qty)->toBe(0)->and($reservation->status)->toBe('closed');
    $balance = DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->first();
    expect($balance->on_hand)->toBe(7)->and($balance->reserved)->toBe(0);

    $detail = app(ReadAdminOrders::class)->detail($catalog['owner'], $orderId);
    expect($detail['shipments'])->toHaveCount(2);
    $upsShipment = collect($detail['shipments'])->firstWhere('carrier', 'UPS');
    expect($upsShipment)->not->toBeNull()
        ->and($upsShipment['lines'])->toBe([['id' => $lineId, 'quantity' => 2]]);
    $detailLine = collect($detail['lines'])->firstWhere('id', $lineId);
    expect($detailLine['shipped_qty'])->toBe(3)->and($detailLine['shippable_quantity'])->toBe(0);
});

it('FulfillmentShipment ships untracked physical lines and mixed carts without inventing stock movement', function (): void {
    $catalog = shipmentCatalog();
    $paid = shipmentPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 1],
        ['variant' => $catalog['poster'], 'quantity' => 2],
    ], 'guest-ship-untracked', 'cs_ship_untracked', 'pi_ship_untracked');
    $orderId = $paid['order_id'];
    expect(DB::table('stock_reservations')->where('intent_id', $orderId)->count())->toBe(1);

    $view = app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'lines' => [
            ['id' => $paid['line_ids'][$catalog['lamp']], 'quantity' => 1],
            ['id' => $paid['line_ids'][$catalog['poster']], 'quantity' => 2],
        ],
    ], (string) Str::uuid());
    expect($view['lines'])->toHaveCount(2);
    expect(DB::table('orders')->where('id', $orderId)->value('fulfillment_status'))->toBe('fulfilled');
    expect(DB::table('order_lines')->where('id', $paid['line_ids'][$catalog['poster']])->value('shipped_qty'))->toBe(2);
    expect(DB::table('stock_movements')->where('reason', 'ship')->count())->toBe(1)
        ->and(DB::table('stock_movements')->where('reason', 'ship')->value('variant_id'))->toBe($catalog['lamp'])
        ->and(DB::table('inventory_balances')->where('variant_id', $catalog['poster'])->count())->toBe(0);
});

it('FulfillmentShipment rejects overshipment, digital lines, unpaid orders and unauthorized staff without persisting anything', function (): void {
    $catalog = shipmentCatalog();
    $paid = shipmentPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 2],
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], 'guest-ship-reject', 'cs_ship_reject', 'pi_ship_reject');
    $orderId = $paid['order_id'];
    $lampLine = $paid['line_ids'][$catalog['lamp']];
    $guideLine = $paid['line_ids'][$catalog['guide']];

    expect(fn () => app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'lines' => [['id' => $lampLine, 'quantity' => 3]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(fn () => app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'lines' => [['id' => $guideLine, 'quantity' => 1]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(fn () => app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'lines' => [['id' => (string) Str::uuid(), 'quantity' => 1]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(fn () => app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'lines' => [],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);

    $pending = shipmentIntent([
        ['variant' => $catalog['mug'], 'quantity' => 1],
    ], 'guest-ship-unpaid');
    expect(fn () => app(CreateShipment::class)->create($catalog['owner'], $pending['order_id'], [
        'lines' => [['id' => $pending['line_ids'][$catalog['mug']], 'quantity' => 1]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);

    $editor = shipmentStaff('content_editor', 'editor-ship@example.test');
    expect(fn () => app(CreateShipment::class)->create($editor, $orderId, [
        'lines' => [['id' => $lampLine, 'quantity' => 1]],
    ], (string) Str::uuid()))->toThrow(AuthorizationException::class);

    expect(DB::table('shipments')->count())->toBe(0)
        ->and(DB::table('order_lines')->where('id', $lampLine)->value('shipped_qty'))->toBe(0)
        ->and(DB::table('stock_movements')->where('reason', 'ship')->count())->toBe(0)
        ->and(DB::table('orders')->where('id', $orderId)->value('fulfillment_status'))->toBe('unfulfilled');

    $shipper = shipmentStaff('fulfillment', 'shipper@example.test');
    $view = app(CreateShipment::class)->create($shipper, $orderId, [
        'lines' => [['id' => $lampLine, 'quantity' => 1]],
    ], (string) Str::uuid());
    expect($view['lines'])->toBe([['id' => $lampLine, 'quantity' => 1]]);
});

it('FulfillmentShipment replays the same key without moving stock twice and conflicts on a changed payload', function (): void {
    $catalog = shipmentCatalog();
    $paid = shipmentPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 2],
    ], 'guest-ship-replay', 'cs_ship_replay', 'pi_ship_replay');
    $orderId = $paid['order_id'];
    $lineId = $paid['line_ids'][$catalog['lamp']];
    $key = (string) Str::uuid();
    $input = ['lines' => [['id' => $lineId, 'quantity' => 1]]];

    $first = app(CreateShipment::class)->create($catalog['owner'], $orderId, $input, $key);
    $replay = app(CreateShipment::class)->create($catalog['owner'], $orderId, $input, $key);
    expect($replay['id'])->toBe($first['id']);
    expect(DB::table('shipments')->where('order_id', $orderId)->count())->toBe(1)
        ->and(DB::table('order_lines')->where('id', $lineId)->value('shipped_qty'))->toBe(1)
        ->and(DB::table('stock_movements')->where('reason', 'ship')->count())->toBe(1)
        ->and(DB::table('order_events')->where('order_id', $orderId)->where('type', 'shipment_created')->count())->toBe(1);

    expect(fn () => app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], $key))->toThrow(FulfillmentIdempotencyConflict::class);
    expect(DB::table('shipments')->where('order_id', $orderId)->count())->toBe(1)
        ->and(DB::table('order_lines')->where('id', $lineId)->value('shipped_qty'))->toBe(1);
});

it('FulfillmentShipment cannot ship quantities held by a refund request', function (): void {
    $catalog = shipmentCatalog();
    $paid = shipmentPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 2],
    ], 'guest-ship-held', 'cs_ship_held', 'pi_ship_held');
    $orderId = $paid['order_id'];
    $lineId = $paid['line_ids'][$catalog['lamp']];
    Http::fake(function (Request $request): mixed {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/refunds')) {
            throw new ConnectionException('timed out after the provider accepted the refund');
        }

        return null;
    });
    app(RequestRefund::class)->request($catalog['owner'], $orderId, [
        'amount' => 0,
        'shipping_amount' => 0,
        'lines' => [['id' => $lineId, 'quantity' => 1]],
        'reason' => 'Customer asked.',
    ], (string) Str::uuid());
    expect(DB::table('order_lines')->where('id', $lineId)->value('held_qty'))->toBe(1);

    expect(fn () => app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    $view = app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());
    expect($view['lines'])->toBe([['id' => $lineId, 'quantity' => 1]]);
    expect(fn () => app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(DB::table('order_lines')->where('id', $lineId)->value('shipped_qty'))->toBe(1)
        ->and(DB::table('shipments')->where('order_id', $orderId)->count())->toBe(1);
});

it('FulfillmentShipment serializes racing submissions so the remaining quantity ships once', function (): void {
    $catalog = shipmentCatalog();
    $paid = shipmentPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 2],
    ], 'guest-ship-race', 'cs_ship_race', 'pi_ship_race');
    $orderId = $paid['order_id'];
    $lineId = $paid['line_ids'][$catalog['lamp']];
    $processes = [];
    $readyFiles = [];
    $start = function () use ($catalog, $orderId, $lineId, &$processes, &$readyFiles): Process {
        $id = (string) Str::uuid();
        $ready = base_path('.runtime/shipment-'.$id.'.ready');
        $readyFiles[] = $ready;
        $process = new Process(
            [base_path('bin/php'), base_path('tests/Fixtures/order-shipment-race.php'), $id, '--env=testing'],
            base_path(),
            ['APP_ENV' => 'testing'],
        );
        $process->setInput(json_encode([
            'staff_id' => $catalog['owner']->id,
            'order_id' => $orderId,
            'line_id' => $lineId,
            'quantity' => 2,
            'idempotency_key' => (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(20)->start();
        $processes[] = $process;

        return $process;
    };
    try {
        DB::beginTransaction();
        DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        $start();
        $start();
        $deadline = microtime(true) + 8;
        while ((! is_file($readyFiles[0]) || ! is_file($readyFiles[1])) && microtime(true) < $deadline) {
            expect($processes[0]->isRunning() && $processes[1]->isRunning())->toBeTrue();
            usleep(20000);
        }
        expect(is_file($readyFiles[0]) && is_file($readyFiles[1]))->toBeTrue();
        DB::commit();
        $statuses = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0);
            $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $statuses[] = $payload['status'];
        }
        sort($statuses);
        expect($statuses)->toBe(['created', 'exhausted'])
            ->and(DB::table('shipments')->where('order_id', $orderId)->count())->toBe(1)
            ->and(DB::table('order_lines')->where('id', $lineId)->value('shipped_qty'))->toBe(2)
            ->and(DB::table('stock_reservations')->where('intent_id', $orderId)->value('shipped_qty'))->toBe(2)
            ->and(DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->value('on_hand'))->toBe(8)
            ->and(DB::table('stock_movements')->where('reason', 'ship')->count())->toBe(1)
            ->and(DB::table('orders')->where('id', $orderId)->value('fulfillment_status'))->toBe('fulfilled');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        foreach ($readyFiles as $ready) {
            if (is_file($ready)) {
                unlink($ready);
            }
        }
    }
});

it('FulfillmentShipment HTTP requires fulfillment permission and fresh 2FA and records the shipment', function (): void {
    $catalog = shipmentCatalog();
    $paid = shipmentPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 2],
    ], 'guest-ship-http', 'cs_ship_http', 'pi_ship_http');
    $orderId = $paid['order_id'];
    $lineId = $paid['line_ids'][$catalog['lamp']];
    $owner = $catalog['owner'];

    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version, 'staff_two_factor_verified' => true])
        ->from('/admin/orders/'.$orderId)
        ->post('/admin/orders/'.$orderId.'/shipments', [
            'lines' => [['id' => $lineId, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/admin/two-factor');
    expect(DB::table('shipments')->where('order_id', $orderId)->count())->toBe(0);

    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/orders/'.$orderId)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('OrderDetail')
        ->where('canShip', true)
        ->where('order.shipments', []));
    $key = (string) Str::uuid();
    $this->from('/admin/orders/'.$orderId)
        ->post('/admin/orders/'.$orderId.'/shipments', [
            'carrier' => 'UPS',
            'tracking_number' => '1Z999AA10123456784',
            'lines' => [['id' => $lineId, 'quantity' => 2]],
            'idempotency_key' => $key,
        ])->assertRedirect('/admin/orders/'.$orderId)->assertSessionHas('order_shipment_saved', true);
    expect(DB::table('shipments')->where('order_id', $orderId)->count())->toBe(1);
    $this->from('/admin/orders/'.$orderId)
        ->post('/admin/orders/'.$orderId.'/shipments', [
            'carrier' => 'UPS',
            'tracking_number' => '1Z999AA10123456784',
            'lines' => [['id' => $lineId, 'quantity' => 2]],
            'idempotency_key' => $key,
        ])->assertRedirect('/admin/orders/'.$orderId);
    expect(DB::table('shipments')->where('order_id', $orderId)->count())->toBe(1)
        ->and(DB::table('order_lines')->where('id', $lineId)->value('shipped_qty'))->toBe(2);
    $this->get('/admin/orders/'.$orderId)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('OrderDetail')
        ->where('order.fulfillment_status', 'fulfilled')
        ->where('order.shipments.0.carrier', 'UPS')
        ->where('order.shipments.0.tracking_number', '1Z999AA10123456784'));

    $editor = shipmentStaff('content_editor', 'editor-ship-http@example.test');
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor))
        ->postJson('/admin/orders/'.$orderId.'/shipments', [
            'lines' => [['id' => $lineId, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
    $this->get('/')->assertOk();
});
