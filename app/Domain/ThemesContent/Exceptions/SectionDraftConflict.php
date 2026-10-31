<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Exceptions;

use RuntimeException;

/**
 * A section draft save named a stale version (THEME-02, T064a). Carries
 * the current version and the stored payload so the editor can show what
 * is actually saved and let the editor reconcile, instead of silently
 * overwriting another editor's work.
 */
final class SectionDraftConflict extends RuntimeException
{
    /** @param list<array<string, mixed>> $sections */
    public function __construct(
        public readonly int $currentVersion,
        public readonly array $sections,
    ) {
        parent::__construct("The draft has changed since it was loaded; it is now at version {$currentVersion}.");
    }
}
