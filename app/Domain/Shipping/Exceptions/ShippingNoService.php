<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Exceptions;

use RuntimeException;

final class ShippingNoService extends RuntimeException
{
    public function __construct(string $message = 'No shipping method covers this destination and physical basket.')
    {
        parent::__construct($message);
    }
}
