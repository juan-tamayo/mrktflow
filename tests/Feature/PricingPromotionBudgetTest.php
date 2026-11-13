<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\ConfirmOrderPayment;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\ExpireCheckout;
use App\Domain\CheckoutOrders\Actions\RequestRefund;
use App\Domain\CheckoutOrders\Actions\StartPayment;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\PaymentSession;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Pricing\Actions\ApplyCoupon;
use App\Domain\Pricing\Actions\HoldPromotionBudget;
use App\Domain\Pricing\Actions\IssuePromotionGrant;
use App\Domain\Pricing\Actions\SavePromotionCampaign;
use App\Domain\Pricing\Actions\SelectPromotions;
use App\Domain\Pricing\Data\PromotionBudgetHold;
use App\Domain\Pricing\Data\PromotionOffer;
use App\Domain\Pricing\Exceptions\PricingCouponConflict;
use App\Domain\Pricing\Exceptions\PricingCouponExhausted;
use App\Domain\Pricing\Exceptions\PricingCouponRejected;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Fixtures\CustomerRegistration;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-11-13T14:00:00Z')->utc());
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

function budgetVerifiedCustomer(string $email): CustomerAccount
{
    $customer = app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), [
        'email' => $email,
    ]));
    $customer->forceFill(['email_verified_at' => now()])->save();

    return $customer->fresh();
}

function budgetHold(string $campaignId, int $revision, int $amount, ?string $customer = null, bool $first = false, ?string $couponId = null, ?string $intent = null): PromotionBudgetHold
{
    return new PromotionBudgetHold(
        $intent ?? (string) Str::uuid(),
        (string) Str::uuid(),
        $campaignId,
        $revision,
        $amount,
        $couponId,
        $customer,
        $first,
        now()->addMinutes(30)->toDateTimeImmutable(),
    );
}

it('PricingPromotionBudget holds commits and releases campaign money with checkout without restoring refunds', function (): void {
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['amount' => 300])]])],
    ]);
    pricingSavedTaxRules($owner);
    $image = catalogReadyImage($owner->id);
    $product = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'price_amount' => 2500,
    ]));
    $variantId = (string) DB::table('product_variants')->where('product_id', $product['id'])->value('id');
    app(AdjustStock::class)->adjust($owner, [
        'variant_id' => $variantId,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 4,
        'low_stock_threshold' => 1,
    ]);
    $campaign = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'reusable',
        'name' => 'Budget ten',
        'discount_budget' => 250,
        'max_discount_amount' => 250,
        'max_redemptions' => 10,
        'max_redemptions_per_customer' => null,
    ]));
    $grant = app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $campaign['id'],
        'issuer' => 'staff',
        'source_intent_id' => (string) Str::uuid(),
        'code' => 'BUDGET10',
    ]);
    $guest = CartOwner::guest('guest-budget-checkout');
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $variantId, 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision'], ['coupon' => 'BUDGET10']));
    expect((int) $quote['discount']['amount'])->toBe(250);
    app(AcceptQuote::class)->accept($guest, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($guest, $quote['id'], (string) Str::uuid());
    expect((int) DB::table('promotion_campaigns')->where('id', $campaign['id'])->value('held_amount'))->toBe(250)
        ->and((string) DB::table('promotion_budget_holds')->where('intent_id', $intent['order_id'])->value('status'))->toBe('active');
    $amount = (int) $intent['grand_total']['amount'];
    Http::fake(function (Request $request) use ($intent, $amount): mixed {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/checkout/sessions')) {
            return Http::response([
                'id' => 'cs_budget_ok',
                'object' => 'checkout.session',
                'amount_total' => $amount,
                'currency' => 'usd',
                'client_reference_id' => $intent['id'],
                'status' => 'open',
                'payment_status' => 'unpaid',
                'expires_at' => Carbon::parse('2026-11-13T14:30:00Z')->utc()->getTimestamp(),
                'url' => 'https://checkout.stripe.com/c/pay/cs_budget_ok',
                'payment_intent' => null,
                'livemode' => false,
                'payment_method_types' => ['card'],
            ], 200);
        }
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/refunds')) {
            $posted = $request->data();
            $meta = is_array($posted['metadata'] ?? null) ? $posted['metadata'] : [];

            return Http::response([
                'id' => 're_budget_ok',
                'object' => 'refund',
                'amount' => (int) ($posted['amount'] ?? 0),
                'payment_intent' => 'pi_budget_ok',
                'status' => 'succeeded',
                'metadata' => $meta,
            ], 200);
        }

        return null;
    });
    app(StartPayment::class)->start($guest, $intent['id']);
    DB::table('promotion_campaigns')->where('id', $campaign['id'])->update(['status' => 'paused']);
    $payment = new PaymentIntent($intent['id'], $amount, 'USD', 'acct_fixture', false);
    app(ConfirmOrderPayment::class)->confirm($intent['id'], PaymentSession::observed(
        $payment,
        $payment,
        'cs_budget_ok',
        'complete',
        'paid',
        Carbon::parse('2026-11-13T14:30:00Z')->utc()->getTimestamp(),
        'pi_budget_ok',
    ));
    expect((string) DB::table('orders')->where('id', $intent['order_id'])->value('status'))->toBe('confirmed')
        ->and((string) DB::table('promotion_budget_holds')->where('intent_id', $intent['order_id'])->value('status'))->toBe('committed')
        ->and((int) DB::table('promotion_campaigns')->where('id', $campaign['id'])->value('held_amount'))->toBe(0)
        ->and((int) DB::table('promotion_campaigns')->where('id', $campaign['id'])->value('redeemed_amount'))->toBe(250);
    app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'id' => $campaign['id'],
        'version' => (int) DB::table('promotion_campaigns')->where('id', $campaign['id'])->value('revision'),
        'kind' => 'reusable',
        'status' => 'paused',
        'name' => 'Budget ten paused',
        'percent_numerator' => 50,
        'discount_budget' => 250,
        'max_discount_amount' => 1250,
        'max_redemptions' => 10,
        'max_redemptions_per_customer' => null,
    ]));
    $lineId = (string) DB::table('order_lines')->where('order_id', $intent['order_id'])->value('id');
    $frozenDiscount = (int) DB::table('order_lines')->where('id', $lineId)->value('discount_amount');
    expect($frozenDiscount)->toBe(250);
    $refund = app(RequestRefund::class)->request($owner, $intent['order_id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Preserve frozen promotion allocation.',
    ], (string) Str::uuid());
    expect($refund['status'])->toBe('succeeded')
        ->and($refund['amount'])->toBe(2475)
        ->and((int) DB::table('promotion_campaigns')->where('id', $campaign['id'])->value('redeemed_amount'))->toBe(250)
        ->and((string) DB::table('promotion_budget_holds')->where('intent_id', $intent['order_id'])->value('status'))->toBe('committed')
        ->and((string) DB::table('refunds')->where('id', $refund['id'])->value('provider_refund_id'))->toBe('re_budget_ok');
    $payload = json_decode(Crypt::decryptString((string) DB::table('orders')->where('id', $intent['order_id'])->value('payload')), true);
    expect($payload['snapshot']['discount']['amount'])->toBe(250)
        ->and($payload['snapshot']['promotions'][0]['campaign_id'] ?? null)->toBe($campaign['id'])
        ->and($payload['snapshot']['promotions'][0]['amount'] ?? null)->toBe(250);
    expect(fn () => app(ApplyCoupon::class)->apply(
        $grant['code'],
        [pricingCouponLine($product['id'], $variantId, 1, pricingCouponUsd(2500))],
        new DateTimeImmutable('2026-11-13T14:00:00Z'),
    ))->toThrow(PricingCouponRejected::class);
    $this->get('/')->assertStatus(503);
});

it('PricingPromotionBudget serializes the last remaining discount budget across real MySQL connections', function (): void {
    $owner = catalogInstalledOwner();
    $campaign = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'automatic',
        'name' => 'Last dollar',
        'mode' => 'fixed',
        'amount' => 100,
        'percent_numerator' => null,
        'discount_budget' => 100,
        'max_redemptions' => 10,
        'max_redemptions_per_customer' => null,
    ]));
    $holder = app(HoldPromotionBudget::class);
    $replay = $holder->hold(budgetHold($campaign['id'], $campaign['revision'], 100));
    $same = $holder->hold(new PromotionBudgetHold(
        $replay->intentId,
        $replay->operationKey,
        $campaign['id'],
        $campaign['revision'],
        100,
        null,
        null,
        false,
        now()->addMinutes(30)->toDateTimeImmutable(),
    ));
    expect($same->holdId)->toBe($replay->holdId);
    $releaseKey = (string) Str::uuid();
    expect($holder->release($replay->intentId, $releaseKey)->status)->toBe('released');
    expect($holder->release($replay->intentId, $releaseKey)->status)->toBe('released');
    $fresh = $holder->hold(budgetHold($campaign['id'], $campaign['revision'], 100));
    expect((int) DB::table('promotion_campaigns')->where('id', $campaign['id'])->value('held_amount'))->toBe(100);
    $holder->commit($fresh->intentId, (string) Str::uuid());
    expect((int) DB::table('promotion_campaigns')->where('id', $campaign['id'])->value('redeemed_amount'))->toBe(100)
        ->and((int) DB::table('promotion_campaigns')->where('id', $campaign['id'])->value('held_amount'))->toBe(0);
    expect(fn () => $holder->hold(budgetHold($campaign['id'], $campaign['revision'], 100)))
        ->toThrow(PricingCouponExhausted::class);
    DB::table('promotion_campaigns')->where('id', $campaign['id'])->update(['redeemed_amount' => 0]);
    $processes = [];
    $readyFiles = [];
    $start = function () use ($campaign, &$processes, &$readyFiles): Process {
        $id = (string) Str::uuid();
        $ready = base_path('.runtime/promotion-budget-'.$id.'.ready');
        $readyFiles[] = $ready;
        $process = new Process(
            [base_path('bin/php'), base_path('tests/Fixtures/promotion-budget-race.php'), $id, '--env=testing'],
            base_path(),
            ['APP_ENV' => 'testing'],
        );
        $process->setInput(json_encode([
            'campaign_id' => $campaign['id'],
            'revision' => $campaign['revision'],
            'intent_id' => (string) Str::uuid(),
            'operation_key' => (string) Str::uuid(),
            'amount' => 100,
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(15)->start();
        $processes[] = $process;

        return $process;
    };
    try {
        DB::beginTransaction();
        DB::table('promotion_campaigns')->where('id', $campaign['id'])->lockForUpdate()->first();
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
            $statuses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
        }
        sort($statuses);
        expect($statuses)->toBe(['exhausted', 'held'])
            ->and(DB::table('promotion_budget_holds')->where('status', 'active')->count())->toBe(1)
            ->and((int) DB::table('promotion_campaigns')->where('id', $campaign['id'])->value('held_amount'))->toBe(100);
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

it('PricingPromotionBudget claims one verified first order and rejects guests existing purchasers and the losing race', function (): void {
    $owner = catalogInstalledOwner();
    $alice = budgetVerifiedCustomer('first-alice@example.test');
    $bob = budgetVerifiedCustomer('first-bob@example.test');
    $unverified = app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), [
        'email' => 'first-unverified@example.test',
    ]));
    $campaign = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'automatic',
        'name' => 'First order ten',
        'first_customer_only' => true,
        'discount_budget' => 500,
        'max_discount_amount' => 250,
        'max_redemptions' => 10,
        'max_redemptions_per_customer' => 1,
    ]));
    $image = catalogReadyImage($owner->id);
    $product = app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    $variantId = (string) DB::table('product_variants')->where('product_id', $product['id'])->value('id');
    $line = pricingCouponLine($product['id'], $variantId, 1, pricingCouponUsd(1000));
    $guestSelection = app(SelectPromotions::class)->select([$line], new DateTimeImmutable('2026-11-13T14:00:00Z'));
    expect(array_values(array_filter(
        $guestSelection->offers,
        fn (PromotionOffer $offer): bool => $offer->status === 'applied',
    )))->toHaveCount(0);
    $unverifiedSelection = app(SelectPromotions::class)->select(
        [$line],
        new DateTimeImmutable('2026-11-13T14:00:00Z'),
        null,
        $unverified->id,
    );
    expect(array_values(array_filter(
        $unverifiedSelection->offers,
        fn (PromotionOffer $offer): bool => $offer->status === 'applied',
    )))->toHaveCount(0);
    $holder = app(HoldPromotionBudget::class);
    expect(fn () => $holder->hold(budgetHold($campaign['id'], $campaign['revision'], 100, null, true)))
        ->toThrow(PricingCouponRejected::class);
    expect(fn () => $holder->hold(budgetHold($campaign['id'], $campaign['revision'], 100, $unverified->id, true)))
        ->toThrow(PricingCouponRejected::class);
    $first = $holder->hold(budgetHold($campaign['id'], $campaign['revision'], 100, $alice->id, true));
    expect($first->status)->toBe('active');
    expect(fn () => $holder->hold(budgetHold($campaign['id'], $campaign['revision'], 100, $alice->id, true)))
        ->toThrow(PricingCouponExhausted::class);
    $holder->commit($first->intentId, (string) Str::uuid());
    $recipient = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'recipient',
        'name' => 'Friend first',
        'first_customer_only' => true,
        'discount_budget' => 250,
        'max_discount_amount' => 250,
        'max_redemptions' => 2,
        'max_redemptions_per_customer' => 1,
    ]));
    $grant = app(IssuePromotionGrant::class)->issue([
        'campaign_id' => $recipient['id'],
        'issuer' => 'referral',
        'source_intent_id' => (string) Str::uuid(),
        'recipient_customer_id' => $bob->id,
    ]);
    expect(fn () => app(ApplyCoupon::class)->apply($grant['code'], [$line], new DateTimeImmutable('2026-11-13T14:00:00Z')))
        ->toThrow(PricingCouponRejected::class);
    expect(fn () => app(ApplyCoupon::class)->apply($grant['code'], [$line], new DateTimeImmutable('2026-11-13T14:00:00Z'), $alice->id))
        ->toThrow(PricingCouponRejected::class);
    $applied = app(ApplyCoupon::class)->apply($grant['code'], [$line], new DateTimeImmutable('2026-11-13T14:00:00Z'), $bob->id);
    expect($applied->code)->toBe($grant['code']);
    $carol = budgetVerifiedCustomer('first-carol@example.test');
    $dave = budgetVerifiedCustomer('first-dave@example.test');
    $open = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'automatic',
        'name' => 'One first slot',
        'first_customer_only' => true,
        'discount_budget' => 100,
        'max_discount_amount' => 100,
        'max_redemptions' => 1,
        'max_redemptions_per_customer' => 1,
    ]));
    $processes = [];
    $readyFiles = [];
    try {
        DB::beginTransaction();
        DB::table('promotion_campaigns')->where('id', $open['id'])->lockForUpdate()->first();
        $start = function (string $customerId) use ($open, &$processes, &$readyFiles): Process {
            $id = (string) Str::uuid();
            $ready = base_path('.runtime/promotion-budget-'.$id.'.ready');
            $readyFiles[] = $ready;
            $process = new Process(
                [base_path('bin/php'), base_path('tests/Fixtures/promotion-budget-race.php'), $id, '--env=testing'],
                base_path(),
                ['APP_ENV' => 'testing'],
            );
            $process->setInput(json_encode([
                'campaign_id' => $open['id'],
                'revision' => $open['revision'],
                'intent_id' => (string) Str::uuid(),
                'operation_key' => (string) Str::uuid(),
                'amount' => 100,
                'customer_key' => $customerId,
                'first_customer' => true,
            ], JSON_THROW_ON_ERROR));
            $process->setTimeout(15)->start();
            $processes[] = $process;

            return $process;
        };
        $start($carol->id);
        $start($dave->id);
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
            $statuses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
        }
        sort($statuses);
        expect($statuses)->toBe(['exhausted', 'held'])
            ->and(DB::table('promotion_budget_holds')->where('campaign_id', $open['id'])->where('status', 'active')->count())->toBe(1);
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
    expect(fn () => app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'automatic',
        'discount_budget' => 500,
        'percent_numerator' => 10,
    ])))->toThrow(ValidationException::class);
    expect(fn () => new PromotionBudgetHold(
        'not-a-uuid',
        (string) Str::uuid(),
        $campaign['id'],
        1,
        100,
        null,
        $alice->id,
        true,
        now()->toDateTimeImmutable(),
    ))->toThrow(InvalidArgumentException::class);
    $this->get('/')->assertStatus(503);
});

it('PricingPromotionBudget expires unused holds and refuses a second payable first-order checkout', function (): void {
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['amount' => 300])]])],
    ]);
    pricingSavedTaxRules($owner);
    $image = catalogReadyImage($owner->id);
    $product = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'price_amount' => 2500,
    ]));
    $variantId = (string) DB::table('product_variants')->where('product_id', $product['id'])->value('id');
    app(AdjustStock::class)->adjust($owner, [
        'variant_id' => $variantId,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 4,
        'low_stock_threshold' => 1,
    ]);
    $alice = budgetVerifiedCustomer('expire-first@example.test');
    $campaign = app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'automatic',
        'name' => 'Welcome first',
        'first_customer_only' => true,
        'discount_budget' => 250,
        'max_discount_amount' => 250,
        'max_redemptions' => 1,
        'max_redemptions_per_customer' => 1,
    ]));
    $ownerCart = CartOwner::customer($alice->id);
    $cart = app(UpdateCart::class)->replaceLine($ownerCart, [
        'variant_id' => $variantId, 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($ownerCart, checkoutQuoteInput($cart['revision'], [
        'email' => $alice->email,
        'coupon' => null,
    ]));
    expect((int) $quote['discount']['amount'])->toBe(250);
    app(AcceptQuote::class)->accept($ownerCart, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($ownerCart, $quote['id'], (string) Str::uuid());
    expect((string) DB::table('promotion_budget_holds')->where('intent_id', $intent['order_id'])->value('status'))->toBe('active')
        ->and((int) DB::table('promotion_budget_holds')->where('intent_id', $intent['order_id'])->value('first_customer'))->toBe(1);
    Carbon::setTestNow(Carbon::parse('2026-11-13T14:31:00Z')->utc());
    app(ExpireCheckout::class)->expire($intent['id']);
    expect((string) DB::table('promotion_budget_holds')->where('intent_id', $intent['order_id'])->value('status'))->toBe('released')
        ->and((int) DB::table('promotion_campaigns')->where('id', $campaign['id'])->value('held_amount'))->toBe(0);
    $holder = app(HoldPromotionBudget::class);
    expect(fn () => $holder->hold(new PromotionBudgetHold(
        $intent['order_id'],
        (string) Str::uuid(),
        $campaign['id'],
        (int) DB::table('promotion_campaigns')->where('id', $campaign['id'])->value('revision'),
        250,
        null,
        $alice->id,
        true,
        now()->addMinutes(30)->toDateTimeImmutable(),
    )))->toThrow(PricingCouponConflict::class);
    $this->get('/')->assertStatus(503);
});
