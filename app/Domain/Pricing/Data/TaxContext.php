<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

use App\Domain\Pricing\Exceptions\PricingInvalidQuote;

final readonly class TaxContext
{
    /**
     * @param  list<TaxableLine>  $lines
     */
    public function __construct(
        public array $lines,
        public string $priceMode,
        public ?TaxDestination $billing,
        public ?TaxDestination $shipping,
        public ?string $shippingTaxClassKey,
    ) {
        if (! in_array($this->priceMode, ['exclusive', 'inclusive'], true) || $this->lines === []) {
            throw new PricingInvalidQuote;
        }
        $seen = [];
        foreach ($this->lines as $line) {
            if (isset($seen[$line->lineId])) {
                throw new PricingInvalidQuote;
            }
            $seen[$line->lineId] = true;
        }
        if ($this->shippingTaxClassKey !== null && preg_match('/^[a-z][a-z0-9_]{1,30}$/', $this->shippingTaxClassKey) !== 1) {
            throw new PricingInvalidQuote;
        }
    }
}
