<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Exceptions;

use DomainException;

final class AiBudgetExhausted extends DomainException
{
    public function __construct()
    {
        parent::__construct('The reserved AI budget has no remaining capacity.');
    }
}
