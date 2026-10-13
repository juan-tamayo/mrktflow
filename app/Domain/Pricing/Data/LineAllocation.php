<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

final readonly class LineAllocation
{
    /**
     * @param  list<int>  $unitDiscounts
     */
    public function __construct(
        public string $lineId,
        public string $variantId,
        public int $quantity,
        public Money $unitPrice,
        public Money $lineSubtotal,
        public Money $discount,
        public Money $lineTotal,
        public array $unitDiscounts,
    ) {}
}
