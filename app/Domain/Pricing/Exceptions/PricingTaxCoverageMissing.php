<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use DomainException;

final class PricingTaxCoverageMissing extends DomainException
{
    public function __construct(string $message = 'Required tax coverage is missing.')
    {
        parent::__construct($message);
    }
}
