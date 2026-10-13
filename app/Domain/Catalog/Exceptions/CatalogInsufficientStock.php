<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use DomainException;

final class CatalogInsufficientStock extends DomainException
{
    public function __construct()
    {
        parent::__construct('Insufficient stock.');
    }
}
