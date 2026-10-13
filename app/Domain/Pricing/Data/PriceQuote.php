<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

use DateTimeImmutable;

final readonly class PriceQuote
{
    /**
     * @param  list<LineAllocation>  $lines
     * @param  list<TaxAllocation>  $taxLines
     */
    public function __construct(
        public array $lines,
        public Money $merchandiseSubtotal,
        public Money $merchandiseDiscount,
        public Money $discountedMerchandise,
        public Money $shipping,
        public Money $tax,
        public Money $grandTotal,
        public DateTimeImmutable $quotedAt,
        public string $fingerprint,
        public string $priceMode = 'exclusive',
        public array $taxLines = [],
    ) {}
}
