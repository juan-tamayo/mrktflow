<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use DomainException;

final class PricingInvalidQuote extends DomainException
{
    public function __construct()
    {
        parent::__construct('This price request cannot be quoted.');
    }
}
