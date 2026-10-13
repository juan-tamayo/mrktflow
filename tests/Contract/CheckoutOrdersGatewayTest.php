<?php

declare(strict_types=1);

use App\Domain\CheckoutOrders\Adapters\StripeGateway;
use App\Domain\CheckoutOrders\Data\HostedCheckoutCharge;
use App\Domain\CheckoutOrders\Data\HostedCheckoutLine;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\PaymentSession;
use App\Domain\CheckoutOrders\Data\RefundIntent;
use App\Domain\CheckoutOrders\Data\RefundResult;
use App\Domain\CheckoutOrders\Support\HostedCheckoutUrl;
use App\Domain\Platform\Support\OutboundHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function checkoutIntent(): PaymentIntent
{
    return new PaymentIntent('14397058-5ad8-4f45-9bbf-b87153dca0ba', 2198, 'USD', 'acct_fixture', false);
}

it('CheckoutOrdersGateway keeps an uncertain or still payable session reserved', function (): void {
    expect(PaymentSession::unknown()->mayReleaseReservation())->toBeFalse();
    foreach (['open', 'complete'] as $state) {
        $session = PaymentSession::observed(checkoutIntent(), checkoutIntent(), 'cs_fixture', $state, 'unpaid', 1792000000, null);
        expect($session->mayReleaseReservation())->toBeFalse();
        expect($session->isPaid())->toBeFalse();
    }
});

it('CheckoutOrdersGateway releases only confirmed expired unpaid sessions and requires capture evidence for success', function (): void {
    $expired = PaymentSession::observed(checkoutIntent(), checkoutIntent(), 'cs_fixture', 'expired', 'unpaid', 1792000000, null);
    expect($expired->mayReleaseReservation())->toBeTrue()->and($expired->isPaid())->toBeFalse();
    $paid = PaymentSession::observed(checkoutIntent(), checkoutIntent(), 'cs_fixture', 'complete', 'paid', 1792000000, 'pi_fixture');
    expect($paid->isPaid())->toBeTrue()->and($paid->mayReleaseReservation())->toBeFalse();
    expect(fn () => PaymentSession::observed(checkoutIntent(), checkoutIntent(), 'cs_fixture', 'complete', 'paid', 1792000000, null))->toThrow(InvalidArgumentException::class);
    expect(fn () => PaymentSession::observed(checkoutIntent(), checkoutIntent(), 'cs_fixture', 'open', 'paid', 1792000000, 'pi_fixture'))->toThrow(InvalidArgumentException::class);
});

it('CheckoutOrdersGateway rejects observations for another amount currency attempt account or mode', function (string $field, mixed $value): void {
    $values = ['attemptId' => checkoutIntent()->attemptId, 'amountMinor' => 2198, 'currency' => 'USD', 'accountId' => 'acct_fixture', 'live' => false];
    $values[$field] = $value;
    $observed = new PaymentIntent(...$values);
    expect(fn () => PaymentSession::observed(checkoutIntent(), $observed, 'cs_fixture', 'complete', 'paid', 1792000000, 'pi_fixture'))->toThrow(InvalidArgumentException::class);
})->with([
    ['amountMinor', 2199], ['currency', 'EUR'], ['attemptId', '24397058-5ad8-4f45-9bbf-b87153dca0ba'], ['accountId', 'acct_other'], ['live', true],
]);

it('CheckoutOrdersGateway uses stable operation keys and holds unknown pending refunds', function (): void {
    $payment = checkoutIntent();
    $refund = new RefundIntent('34397058-5ad8-4f45-9bbf-b87153dca0ba', $payment, 'pi_fixture', 500);
    expect($payment->idempotencyKey())->toBe('payment:'.$payment->attemptId);
    expect($refund->idempotencyKey())->toBe('refund:'.$refund->refundId);
    expect(RefundResult::unknown()->mayReleaseAllocation())->toBeFalse();
    foreach (['pending', 'requires_action', 'succeeded', 'failed', 'canceled'] as $status) {
        $result = RefundResult::observed($refund, $refund, 're_fixture', $status);
        expect($result->mayReleaseAllocation())->toBe(in_array($status, ['failed', 'canceled'], true));
        expect($result->isSucceeded())->toBe($status === 'succeeded');
    }
    $other = new RefundIntent($refund->refundId, $payment, 'pi_other', 500);
    expect(fn () => RefundResult::observed($refund, $other, 're_fixture', 'succeeded'))->toThrow(InvalidArgumentException::class);
});

it('CheckoutOrdersGateway rejects nonpositive or unsafe money and unsupported states', function (): void {
    foreach ([0, -1, 9007199254740992] as $amount) {
        expect(fn () => new PaymentIntent(checkoutIntent()->attemptId, $amount, 'USD', 'acct_fixture', false))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => new RefundIntent('34397058-5ad8-4f45-9bbf-b87153dca0ba', checkoutIntent(), 'pi_fixture', 2199))->toThrow(InvalidArgumentException::class);
    expect(fn () => PaymentSession::observed(checkoutIntent(), checkoutIntent(), 'cs_fixture', 'unknown-new-provider-state', 'unpaid', 1792000000, null))->toThrow(InvalidArgumentException::class);
});

it('CheckoutOrdersGateway rejects refund evidence for changed allocations or identities', function (string $field, mixed $value): void {
    $values = ['refundId' => '34397058-5ad8-4f45-9bbf-b87153dca0ba', 'payment' => checkoutIntent(), 'captureId' => 'pi_fixture', 'amountMinor' => 500];
    $expected = new RefundIntent(...$values);
    $values[$field] = $value;
    $actual = new RefundIntent(...$values);
    expect(fn () => RefundResult::observed($expected, $actual, 're_fixture', 'succeeded'))->toThrow(InvalidArgumentException::class);
})->with([
    ['amountMinor', 501], ['refundId', '44397058-5ad8-4f45-9bbf-b87153dca0ba'],
    ['payment', new PaymentIntent('14397058-5ad8-4f45-9bbf-b87153dca0ba', 2198, 'USD', 'acct_other', false)],
]);

it('CheckoutOrdersGateway rejects invalid references currencies and ambiguous unpaid captures', function (): void {
    expect(fn () => new PaymentIntent('not-an-attempt', 2198, 'USD', 'acct_fixture', false))->toThrow(InvalidArgumentException::class);
    expect(fn () => new PaymentIntent(checkoutIntent()->attemptId, 2198, 'XXX', 'acct_fixture', false))->toThrow(InvalidArgumentException::class);
    expect(fn () => new PaymentIntent(checkoutIntent()->attemptId, 2198, 'USD', "acct_fixture\n", false))->toThrow(InvalidArgumentException::class);
    expect(fn () => PaymentSession::observed(checkoutIntent(), checkoutIntent(), 'cs_fixture', 'expired', 'unpaid', 1792000000, 'pi_processing'))->toThrow(InvalidArgumentException::class);
    $refund = new RefundIntent('34397058-5ad8-4f45-9bbf-b87153dca0ba', checkoutIntent(), 'pi_fixture', 500);
    expect(fn () => RefundResult::observed($refund, $refund, 're_fixture', 'unrecognized'))->toThrow(InvalidArgumentException::class);
});

/**
 * @return array<string, mixed>
 */
function stripeSessionPayload(array $overrides = []): array
{
    return array_replace([
        'id' => 'cs_test_a1B2c3',
        'object' => 'checkout.session',
        'amount_total' => 2198,
        'currency' => 'usd',
        'client_reference_id' => checkoutIntent()->attemptId,
        'status' => 'open',
        'payment_status' => 'unpaid',
        'expires_at' => 1792000000,
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_a1B2c3',
        'payment_intent' => null,
        'livemode' => false,
        'payment_method_types' => ['card'],
    ], $overrides);
}

function hostedCharge(PaymentIntent $intent = new PaymentIntent('14397058-5ad8-4f45-9bbf-b87153dca0ba', 2198, 'USD', 'acct_fixture', false), array $lines = []): HostedCheckoutCharge
{
    if ($lines === []) {
        $lines = [
            new HostedCheckoutLine('Studio Lamp', 1800),
            new HostedCheckoutLine('Shipping', 300),
            new HostedCheckoutLine('Tax', 98),
        ];
    }

    return new HostedCheckoutCharge(
        $intent,
        $lines,
        'https://store.example.test/checkout/payment/'.$intent->attemptId.'/return',
        'https://store.example.test/checkout/payment/'.$intent->attemptId.'/cancel',
        1792000000,
    );
}

function stripeGateway(): StripeGateway
{
    config([
        'services.stripe.secret' => 'sk_test_fixture',
        'services.stripe.account' => 'acct_fixture',
        'services.stripe.live' => false,
    ]);

    return new StripeGateway(new OutboundHttp);
}

it('CheckoutOrdersGateway maps frozen odd-cent physical digital and mixed lines and blocks quote bypasses', function (): void {
    $physical = HostedCheckoutCharge::fromSnapshot(checkoutIntent(), [
        'price_mode' => 'exclusive',
        'grand_total' => ['amount' => 2198, 'currency' => 'USD', 'exponent' => 2],
        'shipping' => ['amount' => 300, 'currency' => 'USD', 'exponent' => 2],
        'tax' => ['amount' => 97, 'currency' => 'USD', 'exponent' => 2],
        'lines' => [
            ['title' => 'Studio Lamp', 'total' => ['amount' => 1801, 'currency' => 'USD', 'exponent' => 2]],
        ],
    ], 'https://store.example.test/checkout/payment/'.checkoutIntent()->attemptId.'/return', 'https://store.example.test/checkout/payment/'.checkoutIntent()->attemptId.'/cancel', 1792000000);
    expect(array_map(fn (HostedCheckoutLine $line): array => [$line->name, $line->amountMinor], $physical->lines))
        ->toBe([['Studio Lamp', 1801], ['Shipping', 300], ['Tax', 97]]);
    $digital = new PaymentIntent('14397058-5ad8-4f45-9bbf-b87153dca0ba', 501, 'USD', 'acct_fixture', false);
    $digitalCharge = HostedCheckoutCharge::fromSnapshot($digital, [
        'price_mode' => 'inclusive',
        'grand_total' => ['amount' => 501, 'currency' => 'USD', 'exponent' => 2],
        'shipping' => ['amount' => 0, 'currency' => 'USD', 'exponent' => 2],
        'tax' => ['amount' => 46, 'currency' => 'USD', 'exponent' => 2],
        'lines' => [
            ['title' => 'Lighting guide', 'total' => ['amount' => 501, 'currency' => 'USD', 'exponent' => 2]],
        ],
    ], 'https://store.example.test/checkout/payment/'.$digital->attemptId.'/return', 'https://store.example.test/checkout/payment/'.$digital->attemptId.'/cancel', 1792000000);
    expect($digitalCharge->lines)->toHaveCount(1)->and($digitalCharge->lines[0]->amountMinor)->toBe(501);
    $mixedIntent = new PaymentIntent('14397058-5ad8-4f45-9bbf-b87153dca0ba', 2805, 'USD', 'acct_fixture', false);
    $mixed = HostedCheckoutCharge::fromSnapshot($mixedIntent, [
        'price_mode' => 'exclusive',
        'grand_total' => ['amount' => 2805, 'currency' => 'USD', 'exponent' => 2],
        'shipping' => ['amount' => 300, 'currency' => 'USD', 'exponent' => 2],
        'tax' => ['amount' => 255, 'currency' => 'USD', 'exponent' => 2],
        'lines' => [
            ['title' => 'Studio Lamp', 'total' => ['amount' => 2250, 'currency' => 'USD', 'exponent' => 2]],
        ],
    ], 'https://store.example.test/checkout/payment/'.$mixedIntent->attemptId.'/return', 'https://store.example.test/checkout/payment/'.$mixedIntent->attemptId.'/cancel', 1792000000);
    expect(array_sum(array_map(fn (HostedCheckoutLine $line): int => $line->amountMinor, $mixed->lines)))->toBe(2805);
    $recorded = null;
    Http::fake(function (Request $request) use (&$recorded) {
        $recorded = $request;

        return Http::response(stripeSessionPayload(['amount_total' => 2198]), 200);
    });
    $result = stripeGateway()->createSession(checkoutIntent(), hostedCharge());
    expect($result->session->state)->toBe('open')
        ->and($result->session->paymentStatus)->toBe('unpaid')
        ->and($result->hostedUrl)->toBe('https://checkout.stripe.com/c/pay/cs_test_a1B2c3')
        ->and($recorded)->not->toBeNull()
        ->and($recorded->header('Idempotency-Key'))->toBe(['payment:'.checkoutIntent()->attemptId])
        ->and($recorded->url())->toBe('https://api.stripe.com/v1/checkout/sessions')
        ->and($recorded->data()['allow_promotion_codes'] ?? null)->toBe('false')
        ->and($recorded->data()['automatic_tax']['enabled'] ?? null)->toBe('false')
        ->and($recorded->data()['mode'] ?? null)->toBe('payment')
        ->and($recorded->data()['payment_method_types'] ?? null)->toBe(['card'])
        ->and($recorded->data()['line_items'][0]['adjustable_quantity']['enabled'] ?? null)->toBe('false')
        ->and($recorded->data()['shipping_address_collection'] ?? null)->toBeNull();
});

it('CheckoutOrdersGateway rejects provider limit violations and unsafe hosted URLs before redirect', function (): void {
    expect(fn () => new HostedCheckoutCharge(
        new PaymentIntent('14397058-5ad8-4f45-9bbf-b87153dca0ba', 49, 'USD', 'acct_fixture', false),
        [new HostedCheckoutLine('Too small', 49)],
        'https://store.example.test/checkout/payment/14397058-5ad8-4f45-9bbf-b87153dca0ba/return',
        'https://store.example.test/checkout/payment/14397058-5ad8-4f45-9bbf-b87153dca0ba/cancel',
        1792000000,
    ))->toThrow(InvalidArgumentException::class);
    expect(fn () => HostedCheckoutCharge::fromSnapshot(checkoutIntent(), [
        'price_mode' => 'exclusive',
        'grand_total' => ['amount' => 2199, 'currency' => 'USD', 'exponent' => 2],
        'shipping' => ['amount' => 0, 'currency' => 'USD', 'exponent' => 2],
        'tax' => ['amount' => 0, 'currency' => 'USD', 'exponent' => 2],
        'lines' => [['title' => 'Lamp', 'total' => ['amount' => 2198, 'currency' => 'USD', 'exponent' => 2]]],
    ], 'https://store.example.test/ok', 'https://store.example.test/cancel', 1792000000))->toThrow(InvalidArgumentException::class);
    Http::fake([
        'https://api.stripe.com/v1/checkout/sessions' => Http::response(stripeSessionPayload([
            'url' => 'https://evil.example/steal',
        ]), 200),
    ]);
    $unsafe = stripeGateway()->createSession(checkoutIntent(), hostedCharge());
    expect($unsafe->session->state)->toBe('unknown')->and($unsafe->hostedUrl)->toBeNull();
    Http::fake([
        'https://api.stripe.com/v1/checkout/sessions' => Http::response(stripeSessionPayload([
            'amount_total' => 2199,
        ]), 200),
    ]);
    $mismatch = stripeGateway()->createSession(checkoutIntent(), hostedCharge());
    expect($mismatch->session->state)->toBe('unknown')->and($mismatch->hostedUrl)->toBeNull();
});

it('CheckoutOrdersGateway persists unknown on timeout and maps hosted success decline authentication and expiry', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));
    $timeout = stripeGateway()->createSession(checkoutIntent(), hostedCharge());
    expect($timeout->session->state)->toBe('unknown')
        ->and($timeout->session->mayReleaseReservation())->toBeFalse()
        ->and($timeout->hostedUrl)->toBeNull();
    expect(stripeGateway()->retrieveSession(checkoutIntent(), null)->session->state)->toBe('unknown');
});

it('CheckoutOrdersGateway maps hosted success authentication expiry refund and cancel observations', function (): void {
    Http::fake(function (Request $request) {
        $url = $request->url();
        if ($request->method() === 'GET' && str_contains($url, '/v1/checkout/sessions/cs_test_a1B2c3')) {
            return Http::response(stripeSessionPayload([
                'status' => 'complete',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_captured',
                'url' => null,
            ]), 200);
        }
        if ($request->method() === 'GET' && str_contains($url, '/v1/checkout/sessions/cs_test_open')) {
            return Http::response(stripeSessionPayload([
                'id' => 'cs_test_open',
                'status' => 'open',
                'payment_status' => 'unpaid',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_open',
            ]), 200);
        }
        if ($request->method() === 'GET' && str_contains($url, '/v1/checkout/sessions/cs_test_expired')) {
            return Http::response(stripeSessionPayload([
                'id' => 'cs_test_expired',
                'status' => 'expired',
                'payment_status' => 'unpaid',
                'url' => null,
            ]), 200);
        }
        if ($request->method() === 'POST' && str_contains($url, '/v1/refunds') && ! str_contains($url, '/re_')) {
            return Http::response([
                'id' => 're_fixture',
                'object' => 'refund',
                'amount' => 500,
                'charge' => null,
                'payment_intent' => 'pi_fixture',
                'status' => 'succeeded',
                'metadata' => ['refund_id' => '34397058-5ad8-4f45-9bbf-b87153dca0ba'],
            ], 200);
        }
        if ($request->method() === 'POST' && str_contains($url, '/expire')) {
            return Http::response(stripeSessionPayload([
                'id' => 'cs_test_a1B2c3',
                'status' => 'expired',
                'payment_status' => 'unpaid',
                'url' => null,
            ]), 200);
        }
        if ($request->method() === 'GET' && str_contains($url, '/v1/refunds/re_fixture')) {
            return Http::response([
                'id' => 're_fixture',
                'object' => 'refund',
                'amount' => 500,
                'payment_intent' => 'pi_fixture',
                'status' => 'succeeded',
                'metadata' => ['refund_id' => '34397058-5ad8-4f45-9bbf-b87153dca0ba'],
            ], 200);
        }

        return Http::response(['error' => ['message' => 'unexpected '.$request->method().' '.$url]], 500);
    });
    $paid = stripeGateway()->retrieveSession(checkoutIntent(), 'cs_test_a1B2c3');
    expect($paid->session->isPaid())->toBeTrue()->and($paid->hostedUrl)->toBeNull();
    $auth = stripeGateway()->retrieveSession(checkoutIntent(), 'cs_test_open');
    expect($auth->session->state)->toBe('open')->and($auth->session->isPaid())->toBeFalse();
    $expired = stripeGateway()->retrieveSession(checkoutIntent(), 'cs_test_expired');
    expect($expired->session->mayReleaseReservation())->toBeTrue();
    $refund = new RefundIntent('34397058-5ad8-4f45-9bbf-b87153dca0ba', checkoutIntent(), 'pi_fixture', 500);
    expect(stripeGateway()->refund($refund)->isSucceeded())->toBeTrue()
        ->and(stripeGateway()->expireSession(checkoutIntent(), 'cs_test_a1B2c3')->mayReleaseReservation())->toBeTrue()
        ->and(stripeGateway()->retrieveRefund($refund, null)->mayReleaseAllocation())->toBeFalse()
        ->and(stripeGateway()->retrieveRefund($refund, 're_fixture')->isSucceeded())->toBeTrue();
    expect(HostedCheckoutUrl::parse('https://checkout.stripe.com/c/pay/cs_test_a1B2c3'))->toBe('https://checkout.stripe.com/c/pay/cs_test_a1B2c3')
        ->and(HostedCheckoutUrl::parse('https://user:pass@checkout.stripe.com/c/pay/cs_test_a1B2c3'))->toBeNull()
        ->and(HostedCheckoutUrl::parse('https://checkout.stripe.com/c/pay/cs_test_a1B2c3#javascript:alert(1)'))->toBeNull();
});

it('CheckoutOrdersGateway treats refused Stripe responses and delayed methods as unknown', function (): void {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            return Http::response(['error' => ['message' => 'bad request']], 400);
        }

        return Http::response(stripeSessionPayload([
            'payment_method_types' => ['card', 'customer_balance'],
            'payment_intent' => ['id' => 'pi_expanded'],
        ]), 200);
    });
    expect(stripeGateway()->createSession(checkoutIntent(), hostedCharge())->session->state)->toBe('unknown')
        ->and(stripeGateway()->createSession(checkoutIntent(), hostedCharge())->session->state)->toBe('unknown');
});
