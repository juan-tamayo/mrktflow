<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use DomainException;

final class CatalogProductNotFound extends DomainException
{
    public function __construct()
    {
        parent::__construct('Product was not found.');
    }
}
