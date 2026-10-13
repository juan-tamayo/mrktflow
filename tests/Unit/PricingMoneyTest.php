<?php

declare(strict_types=1);

use App\Domain\Pricing\Actions\CalculatePrice;
use App\Domain\Pricing\Data\MerchandiseDiscount;
use App\Domain\Pricing\Data\Money;
use App\Domain\Pricing\Data\PricedLine;
use App\Domain\Pricing\Data\PriceRequest;
use App\Domain\Pricing\Exceptions\PricingInvalidCoupon;
use App\Domain\Pricing\Exceptions\PricingInvalidMoney;
use App\Domain\Pricing\Exceptions\PricingInvalidQuote;

function pricingUsd(int $amount): Money
{
    return Money::of($amount, 'USD', 2);
}

function pricingLine(string $id, int $quantity, Money $regular, ?Money $sale = null, ?DateTimeImmutable $starts = null, ?DateTimeImmutable $ends = null): PricedLine
{
    return new PricedLine(
        $id,
        str_replace('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb', $id),
        $quantity,
        $regular,
        $sale,
        $starts,
        $ends,
    );
}

function pricingAt(string $iso): DateTimeImmutable
{
    return new DateTimeImmutable($iso);
}

it('PricingMoney formats zero two and three decimal currencies with integer arithmetic', function (): void {
    expect(Money::of(0, 'USD', 2)->formatted())->toBe('USD 0.00')
        ->and(Money::of(1299, 'USD', 2)->formatted())->toBe('USD 12.99')
        ->and(Money::of(9007199254740991, 'JPY', 0)->formatted())->toBe('JPY 9007199254740991')
        ->and(Money::of(12345, 'KWD', 3)->formatted())->toBe('KWD 12.345');
});

it('PricingMoney rejects unsafe amounts mismatched currencies overflow and floating exponents', function (): void {
    expect(fn () => Money::of(-1, 'USD', 2))->toThrow(PricingInvalidMoney::class);
    expect(fn () => Money::of(9007199254740992, 'USD', 2))->toThrow(PricingInvalidMoney::class);
    expect(fn () => Money::of(100, 'USD', 0))->toThrow(PricingInvalidMoney::class);
    expect(fn () => Money::of(100, 'XXX', 2))->toThrow(PricingInvalidMoney::class);
    $usd = pricingUsd(100);
    expect(fn () => $usd->plus(Money::of(1, 'EUR', 2)))->toThrow(PricingInvalidMoney::class);
    expect(fn () => $usd->times(0))->toThrow(PricingInvalidMoney::class);
    expect(fn () => Money::of(Money::MAX_SAFE, 'USD', 2)->times(2))->toThrow(PricingInvalidMoney::class);
});

it('PricingMoney applies an active sale window and ignores it outside the UTC interval', function (): void {
    $line = pricingLine(
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1',
        2,
        pricingUsd(2000),
        pricingUsd(1500),
        pricingAt('2026-01-01T00:00:00Z'),
        pricingAt('2026-12-31T00:00:00Z'),
    );
    $calculator = new CalculatePrice;
    $during = $calculator->quote(new PriceRequest([$line], pricingAt('2026-06-15T12:00:00Z')));
    $before = $calculator->quote(new PriceRequest([$line], pricingAt('2025-12-31T23:59:59Z')));
    $end = $calculator->quote(new PriceRequest([$line], pricingAt('2026-12-31T00:00:00Z')));
    expect($during->merchandiseSubtotal->amount)->toBe(3000)
        ->and($during->lines[0]->unitPrice->amount)->toBe(1500)
        ->and($before->merchandiseSubtotal->amount)->toBe(4000)
        ->and($before->lines[0]->unitPrice->amount)->toBe(2000)
        ->and($end->merchandiseSubtotal->amount)->toBe(4000);
});

it('PricingMoney rejects a sale above regular or a sale without an explicit UTC window', function (): void {
    $calculator = new CalculatePrice;
    expect(fn () => pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 1, pricingUsd(2000), pricingUsd(2001), pricingAt('2026-01-01T00:00:00Z'), pricingAt('2026-12-31T00:00:00Z')))
        ->toThrow(PricingInvalidQuote::class);
    expect(fn () => pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', 1, pricingUsd(2000), pricingUsd(1500)))
        ->toThrow(PricingInvalidQuote::class);
});

it('PricingMoney allocates the commerce merchandise fixture and odd-cent remainders without floats', function (): void {
    $calculator = new CalculatePrice;
    $a = pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 2, pricingUsd(1000));
    $b = pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', 1, pricingUsd(500));
    $tenPercent = MerchandiseDiscount::percentage(10, 100);
    $quoted = $calculator->quote(new PriceRequest([$a, $b], pricingAt('2026-10-13T00:00:00Z'), $tenPercent, pricingUsd(300)));
    expect($quoted->merchandiseSubtotal->amount)->toBe(2500)
        ->and($quoted->merchandiseDiscount->amount)->toBe(250)
        ->and($quoted->discountedMerchandise->amount)->toBe(2250)
        ->and($quoted->shipping->amount)->toBe(300)
        ->and($quoted->tax->amount)->toBe(0)
        ->and($quoted->grandTotal->amount)->toBe(2550)
        ->and($quoted->lines[0]->discount->amount)->toBe(200)
        ->and($quoted->lines[1]->discount->amount)->toBe(50)
        ->and($quoted->lines[0]->lineTotal->amount + $quoted->lines[1]->lineTotal->amount)->toBe(2250)
        ->and($quoted->lines[0]->unitDiscounts)->toBe([100, 100]);

    $x = pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa4', 1, pricingUsd(333));
    $y = pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa5', 1, pricingUsd(333));
    $z = pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa6', 1, pricingUsd(334));
    $odd = $calculator->quote(new PriceRequest([$x, $y, $z], pricingAt('2026-10-13T00:00:00Z'), MerchandiseDiscount::fixed(pricingUsd(100))));
    expect($odd->merchandiseDiscount->amount)->toBe(100)
        ->and(array_sum(array_map(fn ($line) => $line->discount->amount, $odd->lines)))->toBe(100)
        ->and($odd->discountedMerchandise->amount)->toBe(900)
        ->and($odd->lines[0]->discount->amount)->toBe(33)
        ->and($odd->lines[1]->discount->amount)->toBe(33)
        ->and($odd->lines[2]->discount->amount)->toBe(34);

    $pair = pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa3', 2, pricingUsd(500));
    $units = $calculator->quote(new PriceRequest([$pair], pricingAt('2026-10-13T00:00:00Z'), MerchandiseDiscount::fixed(pricingUsd(7))));
    expect($units->lines[0]->unitDiscounts)->toBe([3, 4])
        ->and(array_sum($units->lines[0]->unitDiscounts))->toBe(7);
});

it('PricingMoney keeps quotes immutable and rejects currency mix coupon over-redemption and empty baskets', function (): void {
    $calculator = new CalculatePrice;
    $line = pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 1, pricingUsd(0));
    $zero = $calculator->quote(new PriceRequest([$line], pricingAt('2026-10-13T00:00:00Z')));
    expect($zero->grandTotal->amount)->toBe(0);
    expect(fn () => $zero->merchandiseSubtotal->amount = 1)->toThrow(Error::class);

    $again = $calculator->quote(new PriceRequest([$line], pricingAt('2026-10-13T00:00:00Z')));
    expect($again->fingerprint)->toBe($zero->fingerprint)->and(strlen($zero->fingerprint))->toBe(64);

    $mixed = pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', 1, Money::of(100, 'EUR', 2));
    expect(fn () => $calculator->quote(new PriceRequest([
        pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 1, pricingUsd(100)),
        $mixed,
    ], pricingAt('2026-10-13T00:00:00Z'))))->toThrow(PricingInvalidQuote::class);

    expect(fn () => $calculator->quote(new PriceRequest([], pricingAt('2026-10-13T00:00:00Z'))))->toThrow(PricingInvalidQuote::class);
    expect(fn () => $calculator->quote(new PriceRequest(
        [pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 1, pricingUsd(100))],
        pricingAt('2026-10-13T00:00:00Z'),
        MerchandiseDiscount::fixed(pricingUsd(101)),
    )))->toThrow(PricingInvalidCoupon::class);

    $physical = pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa7', 1, pricingUsd(1000));
    $digital = pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa8', 1, pricingUsd(500));
    $scoped = $calculator->quote(new PriceRequest(
        [$physical, $digital],
        pricingAt('2026-10-13T00:00:00Z'),
        MerchandiseDiscount::fixed(pricingUsd(100), ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa7']),
    ));
    expect($scoped->lines[0]->discount->amount)->toBe(100)
        ->and($scoped->lines[1]->discount->amount)->toBe(0)
        ->and(pricingUsd(40)->plus(pricingUsd(2))->minus(pricingUsd(7))->toArray())->toMatchArray([
            'amount' => 35, 'currency' => 'USD', 'exponent' => 2, 'formatted' => 'USD 0.35',
        ]);

    $yen = Money::of(100, 'JPY', 0);
    $yenLine = pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa9', 1, $yen);
    $yenQuote = $calculator->quote(new PriceRequest([$yenLine], pricingAt('2026-10-13T00:00:00Z')));
    expect($yenQuote->grandTotal->formatted())->toBe('JPY 100')->and($yenQuote->tax->amount)->toBe(0);
    expect(fn () => Money::scale(4))->toThrow(PricingInvalidMoney::class);
    expect(fn () => MerchandiseDiscount::percentage(0))->toThrow(PricingInvalidCoupon::class);
    expect(fn () => MerchandiseDiscount::fixed(pricingUsd(0)))->toThrow(PricingInvalidCoupon::class);
    expect(fn () => pricingLine('not-a-uuid', 1, pricingUsd(1)))->toThrow(PricingInvalidQuote::class);
    expect(fn () => new PriceRequest(
        [pricingLine('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 1, pricingUsd(100))],
        pricingAt('2026-10-13T00:00:00Z'),
        null,
        Money::of(10, 'EUR', 2),
    ))->toThrow(PricingInvalidQuote::class);
});
