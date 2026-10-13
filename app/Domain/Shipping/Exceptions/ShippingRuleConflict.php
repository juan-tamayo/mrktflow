<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Exceptions;

use RuntimeException;

final class ShippingRuleConflict extends RuntimeException
{
    public function __construct(string $message = 'Shipping zones with the same priority cannot overlap.')
    {
        parent::__construct($message);
    }
}
