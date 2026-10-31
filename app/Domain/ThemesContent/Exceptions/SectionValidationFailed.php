<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Exceptions;

use RuntimeException;

/**
 * A section payload failed validation against the typed registry
 * (THEME-02, T063a). Carries every problem found, each naming the section,
 * the field and what is wrong, so a merchant or an importing tool can fix
 * the payload without a stack trace. Invalid payloads never render.
 */
final class SectionValidationFailed extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Section validation failed: '.implode(' ', $errors));
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
