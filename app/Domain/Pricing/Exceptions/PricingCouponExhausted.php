<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use DomainException;

final class PricingCouponExhausted extends DomainException
{
    public function __construct()
    {
        parent::__construct('This coupon has no remaining redemptions.');
    }
}
