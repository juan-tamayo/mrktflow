<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use DomainException;

final class CatalogInventoryConflict extends DomainException
{
    public function __construct()
    {
        parent::__construct('Inventory operation conflicts with a recorded intent.');
    }
}
