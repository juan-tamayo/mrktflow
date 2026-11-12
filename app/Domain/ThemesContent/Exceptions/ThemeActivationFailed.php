<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Exceptions;

use RuntimeException;

/**
 * Theme activation refused (THEME-06, T068). The active theme is unchanged.
 */
final class ThemeActivationFailed extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Theme activation failed: '.implode(' ', $errors));
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
