<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use DomainException;

final class CatalogCategoryUnpublished extends DomainException
{
    public function __construct()
    {
        parent::__construct('Category is not published.');
    }
}
