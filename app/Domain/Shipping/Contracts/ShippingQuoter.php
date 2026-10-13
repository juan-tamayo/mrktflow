<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Contracts;

use App\Domain\Shipping\Data\ShippingQuote;
use App\Domain\Shipping\Data\ShippingQuoteRequest;

interface ShippingQuoter
{
    public function quote(ShippingQuoteRequest $request): ShippingQuote;
}
