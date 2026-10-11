<?php

declare(strict_types=1);

use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\PaymentSession;
use App\Domain\CheckoutOrders\Data\RefundIntent;
use App\Domain\CheckoutOrders\Data\RefundResult;

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
