<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Exceptions;

use DomainException;

final class ShippingInvalidQuote extends DomainException
{
    public function __construct()
    {
        parent::__construct('This shipping request cannot be quoted.');
    }
}
