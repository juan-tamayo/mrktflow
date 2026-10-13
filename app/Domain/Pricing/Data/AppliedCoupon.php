<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

final readonly class AppliedCoupon
{
    /** @param list<string> $eligibleLineIds */
    public function __construct(
        public string $couponId,
        public int $revision,
        public string $code,
        public string $mode,
        public MerchandiseDiscount $discount,
        public bool $waivesShipping,
        public array $eligibleLineIds,
    ) {}
}
