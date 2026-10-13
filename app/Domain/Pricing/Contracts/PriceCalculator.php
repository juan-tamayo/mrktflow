<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Contracts;

use App\Domain\Pricing\Data\PriceQuote;
use App\Domain\Pricing\Data\PriceRequest;

interface PriceCalculator
{
    public function quote(PriceRequest $request): PriceQuote;
}
