<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Exceptions;

use RuntimeException;

/**
 * Apply named a stale base revision (AI-05). The candidate stays a draft
 * until the author generates from the current content revision.
 */
final class AiDraftConflict extends RuntimeException
{
    public function __construct(public readonly string $currentRevisionId)
    {
        parent::__construct('This page changed since generation. Reload before applying.');
    }
}
