<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use DomainException;

final class CatalogInvalidAllocation extends DomainException
{
    public function __construct()
    {
        parent::__construct('This allocation cannot accept the requested quantity change.');
    }
}
