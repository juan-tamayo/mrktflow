<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

use App\Domain\Pricing\Exceptions\PricingInvalidCoupon;

final readonly class MerchandiseDiscount
{
    /**
     * @param  list<string>|null  $eligibleLineIds
     */
    private function __construct(
        public string $mode,
        public ?Money $fixed,
        public ?int $percentNumerator,
        public int $percentDenominator,
        public ?array $eligibleLineIds,
    ) {}

    public static function none(): self
    {
        return new self('none', null, null, 100, null);
    }

    /** @param  list<string>|null  $eligibleLineIds */
    public static function fixed(Money $amount, ?array $eligibleLineIds = null): self
    {
        if ($amount->amount < 1) {
            throw new PricingInvalidCoupon;
        }

        return new self('fixed', $amount, null, 100, $eligibleLineIds);
    }

    /** @param  list<string>|null  $eligibleLineIds */
    public static function percentage(int $numerator, int $denominator = 100, ?array $eligibleLineIds = null): self
    {
        if ($numerator < 1 || $denominator < 1 || $numerator > $denominator) {
            throw new PricingInvalidCoupon;
        }

        return new self('percentage', null, $numerator, $denominator, $eligibleLineIds);
    }
}
