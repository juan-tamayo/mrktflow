<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Exceptions\CheckoutIdempotencyConflict;
use App\Domain\CheckoutOrders\Exceptions\CheckoutIntentConflict;
use App\Domain\CheckoutOrders\Exceptions\CheckoutQuoteStale;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-13T18:00:00Z')->utc());
});
afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{owner: StaffAccount, physical: string, digital: string, physical_variant: string, digital_variant: string}
 */
function intentCatalog(int $onHand = 4): array
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
        'physical' => $physical['id'],
        'digital' => $digital['id'],
        'physical_variant' => $physicalVariant,
        'digital_variant' => $digitalVariant,
        'digital_version' => $ready['id'],
    ];
}

/**
 * @param  array<string, mixed>  $catalog
 * @return array<string, mixed>
 */
function acceptedIntentQuote(array $catalog, CartOwner $owner, int $quantity = 1, ?string $coupon = 'SAVE10'): array
{
    $cart = app(UpdateCart::class)->replaceLine($owner, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => $quantity, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($owner, checkoutQuoteInput($cart['revision'], [
        'coupon' => $coupon,
    ]));

    return app(AcceptQuote::class)->accept($owner, $quote['id'], $quote['fingerprint'], true);
}

it('CheckoutOrdersIntent freezes the accepted quote, holds stock and coupons, and creates one payable attempt', function (): void {
    $catalog = intentCatalog(2);
    $guest = CartOwner::guest('guest-intent-token');
    $quote = acceptedIntentQuote($catalog, $guest);
    expect($quote['grand_total']['amount'])->toBe(2805);
    $key = (string) Str::uuid();
    $first = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], $key);
    $replay = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], $key);
    expect($replay['id'])->toBe($first['id'])
        ->and($replay['order_id'])->toBe($first['order_id'])
        ->and($replay['order_status'])->toBe('pending')
        ->and($replay['attempt_status'])->toBe('created')
        ->and($replay['grand_total']['amount'])->toBe(2805)
        ->and($replay['zero_total'])->toBeFalse()
        ->and($replay['expires_at'])->toBe('2026-10-13T18:30:00Z');
    expect(DB::table('orders')->count())->toBe(1)
        ->and(DB::table('orders')->where('cart_id', $quote['cart_id'])->where('cart_revision', $quote['cart_revision'])->count())->toBe(1)
        ->and((int) DB::table('inventory_balances')->where('variant_id', $catalog['physical_variant'])->value('reserved'))->toBe(1)
        ->and(DB::table('stock_reservations')->where('intent_id', $first['order_id'])->where('status', 'active')->count())->toBe(1)
        ->and(DB::table('coupon_holds')->where('intent_id', $first['order_id'])->where('status', 'active')->count())->toBe(1)
        ->and(DB::table('payment_attempts')->where('order_id', $first['order_id'])->where('status', 'created')->count())->toBe(1);
    $line = DB::table('order_lines')->where('order_id', $first['order_id'])->first();
    expect($line)->not->toBeNull()
        ->and((string) $line->variant_id)->toBe($catalog['physical_variant'])
        ->and((int) $line->quantity)->toBe(1);
    expect(json_encode($first, JSON_THROW_ON_ERROR))->not->toContain('guest-intent-token', 'guest_token_hash', 'password');
    $this->get('/')->assertOk();
});

it('CheckoutOrdersIntent binds competing keys to one cart revision and rolls back a failed hold', function (): void {
    $catalog = intentCatalog(1);
    $guest = CartOwner::guest('guest-intent-token');
    $quote = acceptedIntentQuote($catalog, $guest);
    $other = CartOwner::guest('other-intent-token');
    $otherQuote = acceptedIntentQuote($catalog, $other, 1, null);
    $first = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], (string) Str::uuid());
    $competitor = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], (string) Str::uuid());
    expect($competitor['order_id'])->toBe($first['order_id'])
        ->and(DB::table('orders')->count())->toBe(1)
        ->and((int) DB::table('inventory_balances')->where('variant_id', $catalog['physical_variant'])->value('reserved'))->toBe(1);
    expect(fn () => app(CreateCheckoutIntent::class)->create($other, $otherQuote['id'], (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    expect(DB::table('orders')->count())->toBe(1)
        ->and(DB::table('coupon_holds')->count())->toBe(1)
        ->and((int) DB::table('inventory_balances')->where('variant_id', $catalog['physical_variant'])->value('reserved'))->toBe(1);
    $stranger = CartOwner::guest('stranger-intent-token');
    expect(fn () => app(CreateCheckoutIntent::class)->create($stranger, $quote['id'], (string) Str::uuid()))
        ->toThrow(CheckoutQuoteStale::class);
});

it('CheckoutOrdersIntent rejects the same idempotency key with a different quote payload', function (): void {
    $catalog = intentCatalog(3);
    $guest = CartOwner::guest('guest-intent-token');
    $firstQuote = acceptedIntentQuote($catalog, $guest);
    $key = (string) Str::uuid();
    app(CreateCheckoutIntent::class)->create($guest, $firstQuote['id'], $key);
    $corrected = app(CreateQuote::class)->create($guest, checkoutQuoteInput($firstQuote['cart_revision'], [
        'coupon' => 'SAVE10',
        'shipping' => checkoutAddress(['postal_code' => '94107', 'city' => 'San Francisco']),
    ]));
    $accepted = app(AcceptQuote::class)->accept($guest, $corrected['id'], $corrected['fingerprint'], true);
    expect(fn () => app(CreateCheckoutIntent::class)->create($guest, $accepted['id'], $key))
        ->toThrow(CheckoutIdempotencyConflict::class);
    expect(DB::table('orders')->count())->toBe(1);
});

it('CheckoutOrdersIntent completes a zero-total digital order locally without a provider charge', function (): void {
    $catalog = intentCatalog();
    pricingSavedCoupon($catalog['owner'], [
        'code' => 'FREE100',
        'percent_numerator' => 100,
        'percent_denominator' => 100,
        'max_redemptions' => 25,
        'max_redemptions_per_customer' => null,
    ]);
    $guest = CartOwner::guest('zero-intent-token');
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['digital_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], [
        'coupon' => 'FREE100',
        'shipping' => null,
    ]));
    expect($quote['grand_total']['amount'])->toBe(0)
        ->and($quote['shipping']['amount'])->toBe(0);
    $accepted = app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($guest, $accepted['id'], (string) Str::uuid());
    expect($intent['zero_total'])->toBeTrue()
        ->and($intent['order_status'])->toBe('confirmed')
        ->and($intent['attempt_status'])->toBe('succeeded')
        ->and($intent['grand_total']['amount'])->toBe(0);
    expect(DB::table('payment_attempts')->where('id', $intent['id'])->value('provider'))->toBe('local')
        ->and(DB::table('coupon_holds')->where('intent_id', $intent['order_id'])->value('status'))->toBe('committed')
        ->and(DB::table('stock_reservations')->where('intent_id', $intent['order_id'])->count())->toBe(0);
    $line = DB::table('order_lines')->where('order_id', $intent['order_id'])->first();
    expect((string) $line->digital_asset_version_id)->toBe($catalog['digital_version']);
});

it('CheckoutOrdersIntent serializes parallel identical submissions across real MySQL connections', function (): void {
    $catalog = intentCatalog(2);
    $guest = CartOwner::guest('race-intent-token');
    $quote = acceptedIntentQuote($catalog, $guest);
    $processes = [];
    $readyFiles = [];
    $start = function (string $key) use ($quote, &$processes, &$readyFiles): Process {
        $id = (string) Str::uuid();
        $ready = base_path('.runtime/checkout-intent-'.$id.'.ready');
        $readyFiles[] = $ready;
        $process = new Process(
            [base_path('bin/php'), base_path('tests/Fixtures/checkout-intent-race.php'), $id, '--env=testing'],
            base_path(),
            ['APP_ENV' => 'testing'],
        );
        $process->setInput(json_encode([
            'guest_token' => 'race-intent-token',
            'quote_id' => $quote['id'],
            'idempotency_key' => $key,
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(20)->start();
        $processes[] = $process;

        return $process;
    };
    $firstKey = (string) Str::uuid();
    $secondKey = (string) Str::uuid();
    try {
        DB::beginTransaction();
        DB::table('checkout_quotes')->where('id', $quote['id'])->lockForUpdate()->first();
        $start($firstKey);
        $start($secondKey);
        $deadline = microtime(true) + 8;
        while ((! is_file($readyFiles[0]) || ! is_file($readyFiles[1])) && microtime(true) < $deadline) {
            expect($processes[0]->isRunning() && $processes[1]->isRunning())->toBeTrue();
            usleep(20000);
        }
        expect(is_file($readyFiles[0]) && is_file($readyFiles[1]))->toBeTrue();
        DB::commit();
        $orders = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0);
            $orders[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['order_id'];
        }
        expect($orders[0])->toBe($orders[1])
            ->and(DB::table('orders')->count())->toBe(1)
            ->and(DB::table('payment_attempts')->count())->toBe(1)
            ->and((int) DB::table('inventory_balances')->where('variant_id', $catalog['physical_variant'])->value('reserved'))->toBe(1);
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

it('CheckoutOrdersIntent HTTP creates a session, replays the same key, and rejects CSRF', function (): void {
    $catalog = intentCatalog();
    $this->get('/')->assertOk();
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['physical_variant'], 'quantity' => 1, 'expectedRevision' => 1,
    ])->assertOk();
    $cart = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    $quote = $this->postJson('/store-api/v1/checkout-quotes', [
        'expectedRevision' => $cart['revision'],
        'email' => 'guest@example.test',
        'coupon' => 'SAVE10',
        'billing' => checkoutAddress(),
        'shipping' => checkoutAddress(),
        'priceAmount' => 1,
    ])->assertOk()->json('data');
    $this->postJson('/store-api/v1/checkout-quotes/'.$quote['id'].'/accept', [
        'fingerprint' => $quote['fingerprint'],
        'policyAcknowledged' => true,
    ])->assertOk();
    $key = (string) Str::uuid();
    $created = $this->postJson('/store-api/v1/checkout-sessions', [
        'quoteId' => $quote['id'],
        'fingerprint' => $quote['fingerprint'],
    ], ['Idempotency-Key' => $key])->assertOk();
    expect($created->json('data.orderStatus'))->toBe('pending')
        ->and($created->json('data.attemptStatus'))->toBe('created')
        ->and($created->json('data.grandTotal.amount'))->toBe(2805)
        ->and($created->json('data.providerRedirect'))->toBeNull()
        ->and($created->headers->get('Cache-Control'))->toContain('no-store');
    $sessionId = $created->json('data.id');
    $this->postJson('/store-api/v1/checkout-sessions', [
        'quoteId' => $quote['id'],
        'fingerprint' => $quote['fingerprint'],
    ], ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.id', $sessionId);
    $this->getJson('/store-api/v1/checkout-sessions/'.$sessionId)->assertOk()
        ->assertJsonPath('data.id', $sessionId)
        ->assertJsonPath('data.orderStatus', 'pending');
    $this->flushSession();
    $this->getJson('/store-api/v1/checkout-sessions/'.$sessionId)->assertStatus(409);
    $this->app->instance('env', 'local');
    $this->postJson('/store-api/v1/checkout-sessions', [
        'quoteId' => $quote['id'],
        'fingerprint' => $quote['fingerprint'],
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(419);
    $this->app->instance('env', 'testing');
});

it('CheckoutOrdersIntent refuses unaccepted expired stale and malformed intents', function (): void {
    $catalog = intentCatalog();
    $guest = CartOwner::guest('guest-intent-token');
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['physical_variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $unaccepted = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], ['coupon' => 'SAVE10']));
    expect(fn () => app(CreateCheckoutIntent::class)->create($guest, $unaccepted['id'], (string) Str::uuid()))
        ->toThrow(CheckoutQuoteStale::class);
    $accepted = app(AcceptQuote::class)->accept($guest, $unaccepted['id'], $unaccepted['fingerprint'], true);
    Carbon::setTestNow(Carbon::parse('2026-10-13T18:16:00Z')->utc());
    expect(fn () => app(CreateCheckoutIntent::class)->create($guest, $accepted['id'], (string) Str::uuid()))
        ->toThrow(CheckoutQuoteStale::class);
    Carbon::setTestNow(Carbon::parse('2026-10-13T18:00:00Z')->utc());
    $staleCart = acceptedIntentQuote($catalog, CartOwner::guest('stale-intent-token'));
    app(UpdateCart::class)->replaceLine(CartOwner::guest('stale-intent-token'), [
        'variant_id' => $catalog['physical_variant'],
        'quantity' => 2,
        'expected_revision' => $staleCart['cart_revision'],
    ]);
    expect(fn () => app(CreateCheckoutIntent::class)->create(
        CartOwner::guest('stale-intent-token'),
        $staleCart['id'],
        (string) Str::uuid(),
    ))->toThrow(CheckoutQuoteStale::class);
    expect(fn () => app(CreateCheckoutIntent::class)->create($guest, 'not-a-uuid', (string) Str::uuid()))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(CreateCheckoutIntent::class)->create($guest, $accepted['id'], strtoupper((string) Str::uuid())))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(CreateCheckoutIntent::class)->snapshot($guest, 'not-a-uuid'))
        ->toThrow(CheckoutQuoteStale::class);
    expect(fn () => app(CreateCheckoutIntent::class)->snapshot($guest, (string) Str::uuid()))
        ->toThrow(CheckoutQuoteStale::class);
    $json = Request::create('/store-api/v1/checkout-sessions', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
    expect((new CheckoutIdempotencyConflict)->render($json)->getStatusCode())->toBe(422)
        ->and((new CheckoutIntentConflict)->render($json)->getStatusCode())->toBe(409);
    $html = Request::create('/checkout', 'POST');
    expect((new CheckoutIdempotencyConflict)->render($html)->isRedirect())->toBeTrue()
        ->and((new CheckoutIntentConflict)->render($html)->isRedirect())->toBeTrue();
});

it('CheckoutOrdersIntent HTTP rejects a missing key and a changed idempotent payload', function (): void {
    $catalog = intentCatalog();
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $catalog['physical_variant'], 'quantity' => 1, 'expectedRevision' => 1,
    ])->assertOk();
    $cart = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    $quote = $this->postJson('/store-api/v1/checkout-quotes', [
        'expectedRevision' => $cart['revision'],
        'email' => 'guest@example.test',
        'coupon' => 'SAVE10',
        'billing' => checkoutAddress(),
        'shipping' => checkoutAddress(),
    ])->assertOk()->json('data');
    $this->postJson('/store-api/v1/checkout-quotes/'.$quote['id'].'/accept', [
        'fingerprint' => $quote['fingerprint'],
        'policyAcknowledged' => true,
    ])->assertOk();
    $this->postJson('/store-api/v1/checkout-sessions', [
        'quoteId' => $quote['id'],
        'fingerprint' => $quote['fingerprint'],
    ])->assertStatus(422);
    $key = (string) Str::uuid();
    $this->postJson('/store-api/v1/checkout-sessions', [
        'quoteId' => $quote['id'],
        'fingerprint' => $quote['fingerprint'],
    ], ['Idempotency-Key' => $key])->assertOk();
    $corrected = $this->postJson('/store-api/v1/checkout-quotes', [
        'expectedRevision' => $cart['revision'],
        'email' => 'guest@example.test',
        'coupon' => 'SAVE10',
        'billing' => checkoutAddress(),
        'shipping' => checkoutAddress(['postal_code' => '94107', 'city' => 'San Francisco']),
    ])->assertOk()->json('data');
    $this->postJson('/store-api/v1/checkout-quotes/'.$corrected['id'].'/accept', [
        'fingerprint' => $corrected['fingerprint'],
        'policyAcknowledged' => true,
    ])->assertOk();
    $conflict = $this->postJson('/store-api/v1/checkout-sessions', [
        'quoteId' => $corrected['id'],
        'fingerprint' => $corrected['fingerprint'],
    ], ['Idempotency-Key' => $key]);
    expect($conflict->status())->toBe(422)
        ->and($conflict->json('error.code'))->toBe('IDEMPOTENCY');
    $this->get('/')->assertOk();
});

it('CheckoutOrdersIntent HTTP keeps privileged staff behind two-factor and leaves the storefront closed', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/products')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/products', ['product' => ['title' => 'Denied']])->assertForbidden();
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->postJson('/admin/taxes', ['rules' => pricingTaxRulesInput()])
        ->assertUnauthorized();
    $this->get('/')->assertOk();
});
