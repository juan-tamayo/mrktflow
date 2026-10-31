<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\ConfirmOrderPayment;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\ReconcileRefund;
use App\Domain\CheckoutOrders\Actions\RequestRefund;
use App\Domain\CheckoutOrders\Actions\StartPayment;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\PaymentSession;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Jobs\ReconcilePendingRefunds;
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
    Carbon::setTestNow(Carbon::parse('2026-10-16T14:00:00Z')->utc());
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
function refundCatalog(int $onHand = 4): array
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
function refundPaidPhysical(string $physicalVariant, string $guestToken, string $sessionId, string $captureId, int $quantity = 1): array
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
                'expires_at' => Carbon::parse('2026-10-16T14:30:00Z')->utc()->getTimestamp(),
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
        Carbon::parse('2026-10-16T14:30:00Z')->utc()->getTimestamp(),
        $captureId,
    ));
    $lineId = (string) DB::table('order_lines')->where('order_id', $intent['order_id'])->value('id');

    return $intent + ['line_id' => $lineId, 'capture_id' => $captureId];
}

/**
 * @param  array<string, mixed>  $refund
 */
function fakeStripeRefund(string $status, string $providerRefundId, int $amount, string $captureId, bool $timeoutCreate = false, ?string $refundId = null): void
{
    Http::fake(function (Request $request) use ($status, $providerRefundId, $amount, $captureId, $timeoutCreate, $refundId): mixed {
        $posted = $request->data();
        $meta = is_array($posted['metadata'] ?? null) ? $posted['metadata'] : [];
        $boundId = is_string($meta['refund_id'] ?? null) ? (string) $meta['refund_id'] : ($refundId ?? '');
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/refunds')) {
            if ($timeoutCreate) {
                throw new ConnectionException('timed out after the provider accepted the refund');
            }
            $postedAmount = is_numeric($posted['amount'] ?? null) ? (int) $posted['amount'] : $amount;
            $metaId = $boundId !== '' ? $boundId : (string) $refundId;
            $id = $metaId !== '' ? 're_'.str_replace('-', '', $metaId) : $providerRefundId;

            return Http::response([
                'id' => $id,
                'object' => 'refund',
                'amount' => $postedAmount,
                'payment_intent' => $captureId,
                'status' => $status,
                'metadata' => $metaId === '' ? [] : ['refund_id' => $metaId],
            ], 200);
        }
        if ($refundId !== null && $request->method() === 'GET' && str_contains($request->url(), '/v1/refunds')) {
            return Http::response([
                'object' => 'list',
                'data' => [[
                    'id' => $providerRefundId,
                    'object' => 'refund',
                    'amount' => $amount,
                    'payment_intent' => $captureId,
                    'status' => $status,
                    'metadata' => ['refund_id' => $refundId],
                ]],
            ], 200);
        }
        if ($request->method() === 'GET' && str_contains($request->url(), '/v1/refunds/'.$providerRefundId)) {
            return Http::response([
                'id' => $providerRefundId,
                'object' => 'refund',
                'amount' => $amount,
                'payment_intent' => $captureId,
                'status' => $status,
                'metadata' => ['refund_id' => $boundId !== '' ? $boundId : (string) $refundId],
            ], 200);
        }

        return null;
    });
}

it('CheckoutOrdersRefund allocates remaining money and quantities, holds unshipped units, and leaves goodwill quantities intact', function (): void {
    $catalog = refundCatalog();
    $owner = $catalog['owner'];
    $paid = refundPaidPhysical($catalog['physical_variant'], 'guest-refund-success', 'cs_refund_ok', 'pi_refund_ok');
    $lineId = $paid['line_id'];
    fakeStripeRefund('succeeded', 're_ok_qty', 2475, 'pi_refund_ok');
    $quantity = app(RequestRefund::class)->request($owner, $paid['order_id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Customer cancelled before shipment.',
    ], (string) Str::uuid());
    expect($quantity['status'])->toBe('succeeded')
        ->and($quantity['amount'])->toBe(2475)
        ->and($quantity['kind'])->toBe('quantity')
        ->and((int) DB::table('order_lines')->where('id', $lineId)->value('held_qty'))->toBe(0)
        ->and((int) DB::table('order_lines')->where('id', $lineId)->value('cancelled_qty'))->toBe(1)
        ->and((int) DB::table('order_lines')->where('id', $lineId)->value('refunded_qty'))->toBe(1)
        ->and((int) DB::table('stock_reservations')->where('intent_id', $paid['order_id'])->value('remaining_qty'))->toBe(0)
        ->and((string) DB::table('stock_reservations')->where('intent_id', $paid['order_id'])->value('status'))->toBe('closed')
        ->and((string) DB::table('orders')->where('id', $paid['order_id'])->value('financial_status'))->toBe('partially_refunded');
    fakeStripeRefund('succeeded', 're_ok_goodwill', 100, 'pi_refund_ok');
    $goodwill = app(RequestRefund::class)->request($owner, $paid['order_id'], [
        'lines' => [],
        'amount' => 100,
        'shipping_amount' => 0,
        'reason' => 'Goodwill adjustment.',
    ], (string) Str::uuid());
    expect($goodwill['status'])->toBe('succeeded')
        ->and($goodwill['kind'])->toBe('goodwill')
        ->and((int) DB::table('order_lines')->where('id', $lineId)->value('cancelled_qty'))->toBe(1)
        ->and((int) DB::table('order_lines')->where('id', $lineId)->value('held_qty'))->toBe(0);
    expect(fn () => app(RequestRefund::class)->request($owner, $paid['order_id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Second quantity refund.',
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(fn () => app(RequestRefund::class)->request($owner, $paid['order_id'], [
        'lines' => [],
        'amount' => 2805,
        'shipping_amount' => 0,
        'reason' => 'Too much goodwill.',
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    $this->get('/')->assertOk();
});

it('CheckoutOrdersRefund clears holds on failure, preserves them when unknown, and reconciles timeout-after-refund', function (): void {
    $catalog = refundCatalog();
    $owner = $catalog['owner'];
    $failedOrder = refundPaidPhysical($catalog['physical_variant'], 'guest-refund-fail', 'cs_refund_fail', 'pi_refund_fail');
    fakeStripeRefund('failed', 're_failed', 2475, 'pi_refund_fail');
    $failed = app(RequestRefund::class)->request($owner, $failedOrder['order_id'], [
        'lines' => [['id' => $failedOrder['line_id'], 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Provider declined.',
    ], (string) Str::uuid());
    expect($failed['status'])->toBe('failed')
        ->and((int) DB::table('order_lines')->where('id', $failedOrder['line_id'])->value('held_qty'))->toBe(0)
        ->and((int) DB::table('order_lines')->where('id', $failedOrder['line_id'])->value('cancelled_qty'))->toBe(0)
        ->and((int) DB::table('stock_reservations')->where('intent_id', $failedOrder['order_id'])->value('remaining_qty'))->toBe(1);

    $unknownOrder = refundPaidPhysical($catalog['physical_variant'], 'guest-refund-unknown', 'cs_refund_unknown', 'pi_refund_unknown');
    $unknownKey = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    fakeStripeRefund('succeeded', 're_timeout', 2475, 'pi_refund_unknown', timeoutCreate: true);
    $unknown = app(RequestRefund::class)->request($owner, $unknownOrder['order_id'], [
        'lines' => [['id' => $unknownOrder['line_id'], 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Timeout after provider refund.',
    ], $unknownKey);
    expect($unknown['status'])->toBe('unknown')
        ->and((int) DB::table('order_lines')->where('id', $unknownOrder['line_id'])->value('held_qty'))->toBe(1)
        ->and((int) DB::table('order_lines')->where('id', $unknownOrder['line_id'])->value('cancelled_qty'))->toBe(0)
        ->and((int) DB::table('stock_reservations')->where('intent_id', $unknownOrder['order_id'])->value('remaining_qty'))->toBe(1);
    fakeStripeRefund('succeeded', 're_timeout', 2475, 'pi_refund_unknown', refundId: $unknown['id']);
    app(ReconcileRefund::class)->reconcile($unknown['id']);
    expect((string) DB::table('refunds')->where('id', $unknown['id'])->value('status'))->toBe('succeeded')
        ->and((int) DB::table('order_lines')->where('id', $unknownOrder['line_id'])->value('held_qty'))->toBe(0)
        ->and((int) DB::table('order_lines')->where('id', $unknownOrder['line_id'])->value('cancelled_qty'))->toBe(1)
        ->and((int) DB::table('stock_reservations')->where('intent_id', $unknownOrder['order_id'])->value('remaining_qty'))->toBe(0);
    $replay = app(RequestRefund::class)->request($owner, $unknownOrder['order_id'], [
        'lines' => [['id' => $unknownOrder['line_id'], 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Timeout after provider refund.',
    ], $unknownKey);
    expect($replay['id'])->toBe($unknown['id'])->and($replay['status'])->toBe('succeeded');
    (new ReconcilePendingRefunds)->handle();
    $this->get('/')->assertOk();
});

it('CheckoutOrdersRefund serializes racing partial refunds so remaining quantity is claimed once', function (): void {
    $catalog = refundCatalog();
    $paid = refundPaidPhysical($catalog['physical_variant'], 'guest-refund-race', 'cs_refund_race', 'pi_refund_race');
    $processes = [];
    $readyFiles = [];
    $start = function () use ($catalog, $paid, &$processes, &$readyFiles): Process {
        $id = (string) Str::uuid();
        $ready = base_path('.runtime/refund-'.$id.'.ready');
        $readyFiles[] = $ready;
        $process = new Process(
            [base_path('bin/php'), base_path('tests/Fixtures/order-refund-race.php'), $id, '--env=testing'],
            base_path(),
            ['APP_ENV' => 'testing'],
        );
        $process->setInput(json_encode([
            'staff_id' => $catalog['owner']->id,
            'order_id' => $paid['order_id'],
            'line_id' => $paid['line_id'],
            'capture_id' => 'pi_refund_race',
            'idempotency_key' => (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(20)->start();
        $processes[] = $process;

        return $process;
    };
    try {
        DB::beginTransaction();
        DB::table('orders')->where('id', $paid['order_id'])->lockForUpdate()->first();
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
        $payloads = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0);
            $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $payloads[] = $payload;
            $statuses[] = $payload['status'];
        }
        sort($statuses);
        expect($statuses)->toBe(['exhausted', 'succeeded'])
            ->and((int) DB::table('order_lines')->where('id', $paid['line_id'])->value('cancelled_qty'))->toBe(1)
            ->and((int) DB::table('order_lines')->where('id', $paid['line_id'])->value('held_qty'))->toBe(0)
            ->and(DB::table('refunds')->where('order_id', $paid['order_id'])->where('status', 'succeeded')->count())->toBe(1);
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

it('CheckoutOrdersRefund HTTP requires refund permission, fresh 2FA, and keeps GET / closed', function (): void {
    $catalog = refundCatalog();
    $paid = refundPaidPhysical($catalog['physical_variant'], 'guest-refund-http', 'cs_refund_http', 'pi_refund_http');
    $owner = $catalog['owner'];
    fakeStripeRefund('succeeded', 're_http', 2475, 'pi_refund_http');
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version, 'staff_two_factor_verified' => true])
        ->from('/admin/orders/'.$paid['order_id'])
        ->post('/admin/orders/'.$paid['order_id'].'/refunds', [
            'lines' => [['id' => $paid['line_id'], 'quantity' => 1]],
            'shipping_amount' => 0,
            'reason' => 'Needs reauthentication.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/admin/two-factor');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/orders/'.$paid['order_id'])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('OrderDetail')
        ->where('canRefund', true)
        ->where('order.refundable.amount', 2805));
    $this->from('/admin/orders/'.$paid['order_id'])
        ->post('/admin/orders/'.$paid['order_id'].'/refunds', [
            'lines' => [['id' => $paid['line_id'], 'quantity' => 1]],
            'shipping_amount' => 0,
            'reason' => 'Unshipped return.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/admin/orders/'.$paid['order_id']);
    expect((int) DB::table('refunds')->where('order_id', $paid['order_id'])->count())->toBe(1);
    $fulfillment = new StaffAccount;
    $fulfillment->forceFill([
        'name' => 'Shipper', 'email' => 'shipper-refund@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'fulfillment', 'permissions' => StaffPermissions::forRole('fulfillment'), 'is_active' => true, 'session_version' => 1,
    ])->save();
    expect(fn () => app(RequestRefund::class)->request($fulfillment, $paid['order_id'], [
        'lines' => [],
        'amount' => 50,
        'reason' => 'No.',
    ], (string) Str::uuid()))->toThrow(AuthorizationException::class);
    $this->actingAs($fulfillment, 'staff')->withSession(catalogStaffSession($fulfillment))
        ->postJson('/admin/orders/'.$paid['order_id'].'/refunds', [
            'lines' => [],
            'amount' => 50,
            'reason' => 'No.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
    $this->get('/')->assertOk();
});
