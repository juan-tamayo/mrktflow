<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

use App\Domain\Pricing\Exceptions\PricingInvalidQuote;
use Illuminate\Support\Str;

final readonly class TaxableLine
{
    public function __construct(
        public string $lineId,
        public string $classKey,
        public string $fulfillment,
    ) {
        if (! Str::isUuid($this->lineId) || $this->lineId !== strtolower($this->lineId)
            || preg_match('/^[a-z][a-z0-9_]{1,30}$/', $this->classKey) !== 1
            || ! in_array($this->fulfillment, ['physical', 'digital'], true)) {
            throw new PricingInvalidQuote;
        }
    }
}
