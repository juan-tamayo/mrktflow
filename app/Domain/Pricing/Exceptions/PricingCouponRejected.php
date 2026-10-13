<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use DomainException;

final class PricingCouponRejected extends DomainException
{
    public function __construct()
    {
        parent::__construct('This coupon cannot be applied.');
    }
}
