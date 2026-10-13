<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use DomainException;

final class CatalogInventoryUntracked extends DomainException
{
    public function __construct()
    {
        parent::__construct('This variant does not track inventory.');
    }
}
