<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Data;

use InvalidArgumentException;

final readonly class HostedCheckoutLine
{
    public function __construct(
        public string $name,
        public int $amountMinor,
    ) {
        if ($name === '' || strlen($name) > 250 || str_contains($name, "\n") || str_contains($name, "\r")
            || $amountMinor < 1 || $amountMinor > 99999999) {
            throw new InvalidArgumentException('Invalid hosted checkout line.');
        }
    }
}
