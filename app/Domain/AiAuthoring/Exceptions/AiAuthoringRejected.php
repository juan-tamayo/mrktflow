<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Exceptions;

use DomainException;

final class AiAuthoringRejected extends DomainException
{
    public function __construct()
    {
        parent::__construct('This AI usage reservation cannot be changed.');
    }
}
