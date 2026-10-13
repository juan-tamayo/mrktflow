<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

use App\Domain\Pricing\Exceptions\PricingInvalidQuote;
use DateTimeImmutable;

final readonly class PriceRequest
{
    /**
     * @param  list<PricedLine>  $lines
     */
    public function __construct(
        public array $lines,
        public DateTimeImmutable $quotedAt,
        public ?MerchandiseDiscount $discount = null,
        public ?Money $shipping = null,
        public ?TaxContext $tax = null,
    ) {
        if ($this->lines === [] || ! PricedLine::isUtc($this->quotedAt)) {
            throw new PricingInvalidQuote;
        }
        $seen = [];
        $currency = $this->lines[0]->regular->currency;
        $exponent = $this->lines[0]->regular->exponent;
        foreach ($this->lines as $line) {
            if (isset($seen[$line->lineId]) || $line->regular->currency !== $currency || $line->regular->exponent !== $exponent) {
                throw new PricingInvalidQuote;
            }
            $seen[$line->lineId] = true;
        }
        if ($this->shipping !== null && ($this->shipping->currency !== $currency || $this->shipping->exponent !== $exponent)) {
            throw new PricingInvalidQuote;
        }
        if ($this->discount?->fixed !== null
            && ($this->discount->fixed->currency !== $currency || $this->discount->fixed->exponent !== $exponent)) {
            throw new PricingInvalidQuote;
        }
    }

    public function discount(): MerchandiseDiscount
    {
        return $this->discount ?? MerchandiseDiscount::none();
    }
}
