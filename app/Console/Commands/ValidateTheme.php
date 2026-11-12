<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ThemesContent\Contracts\ThemeRegistry;
use App\Domain\ThemesContent\Data\ThemeRegistration;
use App\Domain\ThemesContent\Support\DeveloperThemeTrust;
use App\Domain\ThemesContent\Support\ThemeCoverage;
use DomainException;
use Illuminate\Console\Command;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Validate and register a reviewed developer theme (THEME-01, T071).
 *
 * This is the trusted installation workflow. It never activates a theme,
 * never opens the storefront, and never writes catalog, pricing or order
 * rows. Admin data-only import remains a separate, non-executable path.
 */
final class ValidateTheme extends Command
{
    protected $signature = 'theme:validate {directory : Path to a theme directory under themes/}';

    protected $description = 'Validate and register a reviewed developer theme without activating it or editing commerce core';

    public function handle(ThemeRegistry $registry): int
    {
        $argument = (string) $this->argument('directory');
        if ($argument === '') {
            $this->error('Provide a theme directory under themes/.');

            return self::FAILURE;
        }

        try {
            $directory = $this->resolveDirectory($argument);
            $registration = $registry->register($directory);
        } catch (InvalidArgumentException|DomainException|LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $missing = ThemeCoverage::missingSurfaces($this->templateKeys($directory));
        $coverageReady = $registration->status === ThemeRegistration::STATUS_VALID && $missing === [];

        $this->line('id: '.$registration->themeId);
        $this->line('status: '.$registration->status);
        $this->line('trust: '.DeveloperThemeTrust::LEVEL);
        $this->line('sandbox: '.DeveloperThemeTrust::SANDBOX);
        $this->line('admin_upload: '.DeveloperThemeTrust::ADMIN_UPLOAD);
        $this->line('checksum: '.$registration->checksum);
        $this->line('activatable: '.($coverageReady ? 'yes' : 'no'));
        $this->line('missing: '.($missing === [] ? 'none' : implode(', ', $missing)));
        foreach ($registration->errors as $error) {
            $this->line('error: '.$error);
        }

        return $registration->status === ThemeRegistration::STATUS_VALID ? self::SUCCESS : self::FAILURE;
    }

    private function resolveDirectory(string $directory): string
    {
        $root = realpath(base_path('themes'));
        if ($root === false) {
            throw new LogicException('Themes root does not exist.');
        }
        $candidate = str_starts_with($directory, DIRECTORY_SEPARATOR) ? $directory : base_path($directory);
        $real = realpath($candidate);
        if ($real === false || ! is_dir($real)) {
            throw new InvalidArgumentException('Theme directory does not exist.');
        }
        if ($real === $root || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Theme directory must be inside the themes root.');
        }

        return $real;
    }

    /** @return list<string> */
    private function templateKeys(string $directory): array
    {
        try {
            $raw = file_get_contents($directory.DIRECTORY_SEPARATOR.'theme.json');
            if ($raw === false) {
                return [];
            }
            $manifest = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        $templates = is_array($manifest) ? ($manifest['templates'] ?? null) : null;
        if (! is_array($templates) || array_is_list($templates)) {
            return [];
        }
        $keys = [];
        foreach (array_keys($templates) as $key) {
            $keys[] = (string) $key;
        }

        return $keys;
    }
}
