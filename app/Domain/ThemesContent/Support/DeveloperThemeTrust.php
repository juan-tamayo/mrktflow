<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Support;

/**
 * Explicit trust for developer-installed executable themes (THEME-01, THEME-04, T071).
 *
 * Blade, PHP and compiled Vue in a theme directory run with application
 * privileges. The admin importer cannot upload that code; only a reviewed
 * local/repository install under themes/ is accepted.
 */
final class DeveloperThemeTrust
{
    public const LEVEL = 'trusted-developer-code';

    public const SANDBOX = 'none';

    public const ADMIN_UPLOAD = 'refused';
}
