<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use DomainException;

final class PricingInvalidCoupon extends DomainException
{
    public function __construct()
    {
        parent::__construct('This merchandise discount cannot be applied.');
    }
}
