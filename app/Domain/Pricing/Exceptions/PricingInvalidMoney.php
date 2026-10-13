<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use InvalidArgumentException;

final class PricingInvalidMoney extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Unsupported money.');
    }
}
