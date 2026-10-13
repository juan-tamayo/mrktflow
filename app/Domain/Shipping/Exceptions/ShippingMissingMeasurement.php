<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Exceptions;

use RuntimeException;

final class ShippingMissingMeasurement extends RuntimeException
{
    public function __construct(string $message = 'A weight-based shipping method needs the physical line weight.')
    {
        parent::__construct($message);
    }
}
