<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use RuntimeException;

final class PricingTaxRuleConflict extends RuntimeException
{
    public function __construct(string $message = 'Tax rules with the same specificity cannot overlap.')
    {
        parent::__construct($message);
    }
}
