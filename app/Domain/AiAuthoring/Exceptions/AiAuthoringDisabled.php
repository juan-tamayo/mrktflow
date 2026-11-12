<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Exceptions;

use DomainException;

final class AiAuthoringDisabled extends DomainException
{
    public function __construct()
    {
        parent::__construct('Built-in AI is turned off.');
    }
}
