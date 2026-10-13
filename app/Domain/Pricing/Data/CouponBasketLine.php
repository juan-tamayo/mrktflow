<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

use App\Domain\Pricing\Exceptions\PricingInvalidQuote;
use Illuminate\Support\Str;

final readonly class CouponBasketLine
{
    /** @param list<string> $categoryIds */
    public function __construct(
        public PricedLine $line,
        public string $productId,
        public array $categoryIds = [],
    ) {
        if (! Str::isUuid($this->productId) || $this->productId !== strtolower($this->productId)) {
            throw new PricingInvalidQuote;
        }
        $seen = [];
        foreach ($this->categoryIds as $categoryId) {
            if (! Str::isUuid($categoryId) || $categoryId !== strtolower($categoryId) || isset($seen[$categoryId])) {
                throw new PricingInvalidQuote;
            }
            $seen[$categoryId] = true;
        }
    }
}
