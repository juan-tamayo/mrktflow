<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Data;

use App\Domain\Shipping\Exceptions\ShippingInvalidQuote;
use Illuminate\Support\Str;

final readonly class ShippingQuoteRequest
{
    /**
     * @param  list<ShippingQuoteLine>  $lines
     */
    public function __construct(
        public array $lines,
        public ?ShippingDestination $destination,
        public ?string $methodId,
    ) {
        if ($this->lines === []) {
            throw new ShippingInvalidQuote;
        }
        if ($this->methodId !== null && (! Str::isUuid($this->methodId) || $this->methodId !== strtolower($this->methodId))) {
            throw new ShippingInvalidQuote;
        }
        $seen = [];
        foreach ($this->lines as $line) {
            if (isset($seen[$line->lineId])) {
                throw new ShippingInvalidQuote;
            }
            $seen[$line->lineId] = true;
        }
    }
}
