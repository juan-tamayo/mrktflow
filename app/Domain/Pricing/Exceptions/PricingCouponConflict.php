<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use DomainException;

final class PricingCouponConflict extends DomainException
{
    public function __construct()
    {
        parent::__construct('This coupon operation conflicts with an existing hold.');
    }
}
