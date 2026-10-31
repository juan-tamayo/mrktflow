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
use App\Domain\Fulfillment\Actions\ApproveReturn;
use App\Domain\Fulfillment\Actions\CreateShipment;
use App\Domain\Fulfillment\Actions\ReceiveReturn;
use App\Domain\Fulfillment\Actions\RecordReturn;
use App\Domain\Fulfillment\Actions\RestockReturn;
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
function returnsCatalog(): array
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
function returnsIntent(array $lines, string $guestToken): array
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
function returnsPaidOrder(array $lines, string $guestToken, string $sessionId, string $captureId): array
{
    $intent = returnsIntent($lines, $guestToken);
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

function returnsStaff(string $role, string $email): StaffAccount
{
    $staff = new StaffAccount;
    $staff->forceFill([
        'name' => 'Staff '.$role, 'email' => $email,
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => $role, 'permissions' => StaffPermissions::forRole($role), 'is_active' => true, 'session_version' => 1,
    ])->save();

    return $staff;
}

/**
 * @param  array{owner: StaffAccount, lamp: string, mug: string, poster: string, guide: string}  $catalog
 * @return array<string, mixed>
 */
function returnsShippedOrder(array $catalog, int $quantity, int $ship, string $token): array
{
    $paid = returnsPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => $quantity],
    ], $token, 'cs_'.$token, 'pi_'.$token);
    $lineId = $paid['line_ids'][$catalog['lamp']];
    app(CreateShipment::class)->create($catalog['owner'], $paid['order_id'], [
        'lines' => [['id' => $lineId, 'quantity' => $ship]],
    ], (string) Str::uuid());
    $paid['lamp_line'] = $lineId;

    return $paid;
}

it('FulfillmentReturns walks request approval receipt and deliberate restock with stock moving only at restock', function (): void {
    $catalog = returnsCatalog();
    $paid = returnsShippedOrder($catalog, 3, 3, 'guest-return-lifecycle');
    $orderId = $paid['order_id'];
    $lineId = $paid['lamp_line'];
    $balance = DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->first();
    expect($balance->on_hand)->toBe(7)->and($balance->reserved)->toBe(0);

    $view = app(RecordReturn::class)->record($catalog['owner'], $orderId, [
        'reason' => 'Customer changed their mind.',
        'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid());
    expect($view['status'])->toBe('requested')
        ->and($view['lines'])->toBe([['id' => $lineId, 'requested' => 2, 'approved' => 0, 'received' => 0, 'restocked' => 0]]);
    expect(DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->value('on_hand'))->toBe(7)
        ->and(DB::table('stock_movements')->where('reason', 'restock')->count())->toBe(0)
        ->and(DB::table('refunds')->where('order_id', $orderId)->count())->toBe(0);

    $view = app(ApproveReturn::class)->approve($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid());
    expect($view['status'])->toBe('approved')
        ->and($view['lines'][0]['approved'])->toBe(2);

    $view = app(ReceiveReturn::class)->receive($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());
    expect($view['status'])->toBe('partially_received')
        ->and($view['lines'][0]['received'])->toBe(1);
    expect(DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->value('on_hand'))->toBe(7)
        ->and(DB::table('stock_movements')->where('reason', 'restock')->count())->toBe(0);

    $view = app(ReceiveReturn::class)->receive($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());
    expect($view['status'])->toBe('received')
        ->and($view['lines'][0]['received'])->toBe(2);
    expect(DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->value('on_hand'))->toBe(7);

    $view = app(RestockReturn::class)->restock($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());
    expect($view['lines'][0]['restocked'])->toBe(1);
    $balance = DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->first();
    expect($balance->on_hand)->toBe(8)->and($balance->reserved)->toBe(0);
    $movement = DB::table('stock_movements')->where('variant_id', $catalog['lamp'])->where('reason', 'restock')->first();
    expect($movement)->not->toBeNull()->and($movement->on_hand_delta)->toBe(1)->and($movement->reserved_delta)->toBe(0);

    $view = app(RestockReturn::class)->restock($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());
    expect($view['lines'][0]['restocked'])->toBe(2);
    expect(DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->value('on_hand'))->toBe(9)
        ->and(DB::table('stock_movements')->where('reason', 'restock')->count())->toBe(2);

    foreach (['return_requested', 'return_approved', 'return_received', 'return_restocked'] as $type) {
        expect(DB::table('order_events')->where('order_id', $orderId)->where('type', $type)->count())
            ->toBeGreaterThanOrEqual(1);
    }
    expect(DB::table('order_events')->where('order_id', $orderId)->where('type', 'return_requested')->count())->toBe(1)
        ->and(DB::table('orders')->where('id', $orderId)->value('fulfillment_status'))->toBe('fulfilled')
        ->and(DB::table('orders')->where('id', $orderId)->value('financial_status'))->toBe('paid');

    $detail = app(ReadAdminOrders::class)->detail($catalog['owner'], $orderId);
    expect($detail['returns'])->toHaveCount(1);
    $summary = $detail['returns'][0];
    expect($summary['status'])->toBe('received')
        ->and($summary['reason'])->toBe('Customer changed their mind.')
        ->and($summary['lines'])->toBe([[
            'id' => $lineId, 'requested' => 2, 'approved' => 2, 'received' => 2, 'restocked' => 2, 'refunds' => [],
        ]]);
    $detailLine = collect($detail['lines'])->firstWhere('id', $lineId);
    expect($detailLine['returned_qty'])->toBe(2)
        ->and($detailLine['restocked_qty'])->toBe(2)
        ->and($detailLine['returnable_quantity'])->toBe(1);
});

it('FulfillmentReturns enforces shipped and stage bounds and rejects unauthorized or unpaid returns without persisting', function (): void {
    $catalog = returnsCatalog();
    $paid = returnsShippedOrder($catalog, 3, 2, 'guest-return-bounds');
    $orderId = $paid['order_id'];
    $lineId = $paid['lamp_line'];

    expect(fn () => app(RecordReturn::class)->record($catalog['owner'], $orderId, [
        'reason' => 'Too many.', 'lines' => [['id' => $lineId, 'quantity' => 3]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(fn () => app(RecordReturn::class)->record($catalog['owner'], $orderId, [
        'reason' => '', 'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(fn () => app(RecordReturn::class)->record($catalog['owner'], $orderId, [
        'reason' => 'No lines.', 'lines' => [],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);

    $pending = returnsIntent([
        ['variant' => $catalog['mug'], 'quantity' => 1],
    ], 'guest-return-unpaid');
    expect(fn () => app(RecordReturn::class)->record($catalog['owner'], $pending['order_id'], [
        'reason' => 'Not paid.', 'lines' => [['id' => $pending['line_ids'][$catalog['mug']], 'quantity' => 1]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);

    $editor = returnsStaff('content_editor', 'editor-return@example.test');
    expect(fn () => app(RecordReturn::class)->record($editor, $orderId, [
        'reason' => 'No permission.', 'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid()))->toThrow(AuthorizationException::class);
    expect(DB::table('order_returns')->count())->toBe(0);

    $view = app(RecordReturn::class)->record($catalog['owner'], $orderId, [
        'reason' => 'Damaged in transit.', 'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid());
    expect(fn () => app(ReceiveReturn::class)->receive($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(fn () => app(RestockReturn::class)->restock($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(fn () => app(ApproveReturn::class)->approve($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 3]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);

    $view = app(ApproveReturn::class)->approve($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());
    expect(fn () => app(ApproveReturn::class)->approve($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(fn () => app(ReceiveReturn::class)->receive($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);

    $view = app(ReceiveReturn::class)->receive($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());
    expect(fn () => app(RestockReturn::class)->restock($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(DB::table('order_returns')->count())->toBe(1)
        ->and(DB::table('stock_movements')->where('reason', 'restock')->count())->toBe(0)
        ->and(DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->value('on_hand'))->toBe(8);
});

it('FulfillmentReturns partial approval limits later requests and a full rejection frees the quantities', function (): void {
    $catalog = returnsCatalog();
    $paid = returnsShippedOrder($catalog, 3, 3, 'guest-return-partial');
    $orderId = $paid['order_id'];
    $lineId = $paid['lamp_line'];

    $first = app(RecordReturn::class)->record($catalog['owner'], $orderId, [
        'reason' => 'First return.', 'lines' => [['id' => $lineId, 'quantity' => 3]],
    ], (string) Str::uuid());
    $first = app(ApproveReturn::class)->approve($catalog['owner'], $orderId, $first['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());
    expect($first['status'])->toBe('approved')->and($first['lines'][0]['approved'])->toBe(1);

    expect(fn () => app(RecordReturn::class)->record($catalog['owner'], $orderId, [
        'reason' => 'Too many again.', 'lines' => [['id' => $lineId, 'quantity' => 3]],
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    $second = app(RecordReturn::class)->record($catalog['owner'], $orderId, [
        'reason' => 'Second return.', 'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid());
    expect($second['status'])->toBe('requested');

    $second = app(ApproveReturn::class)->approve($catalog['owner'], $orderId, $second['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 0]],
    ], (string) Str::uuid());
    expect($second['status'])->toBe('rejected')->and($second['lines'][0]['approved'])->toBe(0);

    $third = app(RecordReturn::class)->record($catalog['owner'], $orderId, [
        'reason' => 'Third return.', 'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid());
    expect($third['status'])->toBe('requested');
    $detail = app(ReadAdminOrders::class)->detail($catalog['owner'], $orderId);
    $detailLine = collect($detail['lines'])->firstWhere('id', $lineId);
    $statuses = collect($detail['returns'])->mapWithKeys(fn (array $summary): array => [$summary['id'] => $summary['status']]);
    expect($detailLine['returnable_quantity'])->toBe(0)
        ->and($statuses[$first['id']])->toBe('approved')
        ->and($statuses[$second['id']])->toBe('rejected')
        ->and($statuses[$third['id']])->toBe('requested');
});

it('FulfillmentReturns replays duplicate requests without a second effect and conflicts on changed payloads', function (): void {
    $catalog = returnsCatalog();
    $paid = returnsShippedOrder($catalog, 3, 3, 'guest-return-replay');
    $orderId = $paid['order_id'];
    $lineId = $paid['lamp_line'];
    $key = (string) Str::uuid();
    $input = ['reason' => 'Duplicate check.', 'lines' => [['id' => $lineId, 'quantity' => 2]]];

    $first = app(RecordReturn::class)->record($catalog['owner'], $orderId, $input, $key);
    $replay = app(RecordReturn::class)->record($catalog['owner'], $orderId, $input, $key);
    expect($replay['id'])->toBe($first['id']);
    expect(DB::table('order_returns')->where('order_id', $orderId)->count())->toBe(1)
        ->and(DB::table('order_events')->where('order_id', $orderId)->where('type', 'return_requested')->count())->toBe(1);
    expect(fn () => app(RecordReturn::class)->record($catalog['owner'], $orderId, [
        'reason' => 'Duplicate check.', 'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], $key))->toThrow(FulfillmentIdempotencyConflict::class);
    expect(DB::table('order_returns')->where('order_id', $orderId)->count())->toBe(1);

    app(ApproveReturn::class)->approve($catalog['owner'], $orderId, $first['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid());
    app(ReceiveReturn::class)->receive($catalog['owner'], $orderId, $first['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid());
    expect(DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->value('on_hand'))->toBe(7);

    $restockKey = (string) Str::uuid();
    $restockInput = ['lines' => [['id' => $lineId, 'quantity' => 2]]];
    $restocked = app(RestockReturn::class)->restock($catalog['owner'], $orderId, $first['id'], $restockInput, $restockKey);
    expect($restocked['lines'][0]['restocked'])->toBe(2);
    expect(DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->value('on_hand'))->toBe(9)
        ->and(DB::table('stock_movements')->where('reason', 'restock')->count())->toBe(1);

    $restockReplay = app(RestockReturn::class)->restock($catalog['owner'], $orderId, $first['id'], $restockInput, $restockKey);
    expect($restockReplay['lines'][0]['restocked'])->toBe(2);
    expect(DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->value('on_hand'))->toBe(9)
        ->and(DB::table('stock_movements')->where('reason', 'restock')->count())->toBe(1)
        ->and(DB::table('order_events')->where('order_id', $orderId)->where('type', 'return_restocked')->count())->toBe(1);
    expect(fn () => app(RestockReturn::class)->restock($catalog['owner'], $orderId, $first['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], $restockKey))->toThrow(FulfillmentIdempotencyConflict::class);
    expect(DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->value('on_hand'))->toBe(9);
});

it('FulfillmentReturns keeps refunds and returns separate while the summary links the refund facts', function (): void {
    $catalog = returnsCatalog();
    $paid = returnsShippedOrder($catalog, 3, 2, 'guest-return-refund');
    $orderId = $paid['order_id'];
    $lineId = $paid['lamp_line'];
    $balance = DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->first();
    expect($balance->on_hand)->toBe(8)->and($balance->reserved)->toBe(1);

    Http::fake(function (Request $request): mixed {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/refunds')) {
            throw new ConnectionException('timed out after the provider accepted the refund');
        }

        return null;
    });
    $refund = app(RequestRefund::class)->request($catalog['owner'], $orderId, [
        'amount' => 0,
        'shipping_amount' => 0,
        'lines' => [['id' => $lineId, 'quantity' => 1]],
        'reason' => 'Unshipped unit refunded.',
    ], (string) Str::uuid());
    expect($refund['status'])->toBe('unknown');
    $balance = DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->first();
    expect($balance->on_hand)->toBe(8)->and($balance->reserved)->toBe(1)
        ->and(DB::table('stock_movements')->where('reason', 'restock')->count())->toBe(0);

    $view = app(RecordReturn::class)->record($catalog['owner'], $orderId, [
        'reason' => 'Two units came back.', 'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid());
    app(ApproveReturn::class)->approve($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid());
    app(ReceiveReturn::class)->receive($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid());
    expect(DB::table('refunds')->where('order_id', $orderId)->count())->toBe(1)
        ->and(DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->value('on_hand'))->toBe(8);
    app(RestockReturn::class)->restock($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid());
    $balance = DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->first();
    expect($balance->on_hand)->toBe(10)->and($balance->reserved)->toBe(1);

    $detail = app(ReadAdminOrders::class)->detail($catalog['owner'], $orderId);
    $summary = $detail['returns'][0];
    expect($summary['lines'][0]['refunds'])->toHaveCount(1);
    $link = $summary['lines'][0]['refunds'][0];
    expect($link['id'])->toBe($refund['id'])
        ->and($link['kind'])->toBe('quantity')
        ->and($link['status'])->toBe('unknown')
        ->and($link['quantity'])->toBe(1)
        ->and($link['amount']['amount'])->toBe($refund['amount']);
});

it('FulfillmentReturns restocks untracked lines as records without inventing stock balances', function (): void {
    $catalog = returnsCatalog();
    $paid = returnsPaidOrder([
        ['variant' => $catalog['poster'], 'quantity' => 2],
    ], 'guest-return-untracked', 'cs_return_untracked', 'pi_return_untracked');
    $orderId = $paid['order_id'];
    $lineId = $paid['line_ids'][$catalog['poster']];
    app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'lines' => [['id' => $lineId, 'quantity' => 2]],
    ], (string) Str::uuid());

    $view = app(RecordReturn::class)->record($catalog['owner'], $orderId, [
        'reason' => 'Poster came back.', 'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());
    app(ApproveReturn::class)->approve($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());
    app(ReceiveReturn::class)->receive($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());
    $view = app(RestockReturn::class)->restock($catalog['owner'], $orderId, $view['id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
    ], (string) Str::uuid());
    expect($view['lines'][0]['restocked'])->toBe(1);
    expect(DB::table('inventory_balances')->where('variant_id', $catalog['poster'])->count())->toBe(0)
        ->and(DB::table('stock_movements')->where('variant_id', $catalog['poster'])->count())->toBe(0);
    $detail = app(ReadAdminOrders::class)->detail($catalog['owner'], $orderId);
    $detailLine = collect($detail['lines'])->firstWhere('id', $lineId);
    expect($detailLine['returned_qty'])->toBe(1)->and($detailLine['restocked_qty'])->toBe(1);
});

it('FulfillmentReturns HTTP requires fulfillment permission and fresh 2FA and walks the steps', function (): void {
    $catalog = returnsCatalog();
    $paid = returnsShippedOrder($catalog, 2, 2, 'guest-return-http');
    $orderId = $paid['order_id'];
    $lineId = $paid['lamp_line'];
    $owner = $catalog['owner'];

    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version, 'staff_two_factor_verified' => true])
        ->from('/admin/orders/'.$orderId)
        ->post('/admin/orders/'.$orderId.'/returns', [
            'reason' => 'HTTP return.',
            'lines' => [['id' => $lineId, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/admin/two-factor');
    expect(DB::table('order_returns')->where('order_id', $orderId)->count())->toBe(0);

    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/orders/'.$orderId)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('OrderDetail')
        ->where('canReturn', true)
        ->where('order.returns', []));
    $this->from('/admin/orders/'.$orderId)
        ->post('/admin/orders/'.$orderId.'/returns', [
            'reason' => 'HTTP return.',
            'lines' => [['id' => $lineId, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/admin/orders/'.$orderId)->assertSessionHas('order_return_saved', true);
    $returnId = (string) DB::table('order_returns')->where('order_id', $orderId)->value('id');
    expect($returnId)->not->toBe('');
    foreach (['approve', 'receive', 'restock'] as $step) {
        $this->from('/admin/orders/'.$orderId)
            ->post('/admin/orders/'.$orderId.'/returns/'.$returnId.'/'.$step, [
                'lines' => [['id' => $lineId, 'quantity' => 1]],
                'idempotency_key' => (string) Str::uuid(),
            ])->assertRedirect('/admin/orders/'.$orderId)->assertSessionHas('order_return_saved', true);
    }
    expect(DB::table('order_returns')->where('id', $returnId)->value('status'))->toBe('received')
        ->and(DB::table('return_lines')->where('return_id', $returnId)->value('restocked_qty'))->toBe(1)
        ->and(DB::table('inventory_balances')->where('variant_id', $catalog['lamp'])->value('on_hand'))->toBe(9);
    $this->get('/admin/orders/'.$orderId)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('OrderDetail')
        ->where('order.returns.0.status', 'received')
        ->where('order.returns.0.lines.0.restocked', 1));

    $editor = returnsStaff('content_editor', 'editor-return-http@example.test');
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor))
        ->postJson('/admin/orders/'.$orderId.'/returns', [
            'reason' => 'No permission.',
            'lines' => [['id' => $lineId, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
    $this->get('/')->assertOk();
});
