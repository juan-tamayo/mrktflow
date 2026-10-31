<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Data;

/**
 * One installed theme's registration record (THEME-01, T061).
 *
 * Validity is an explicit recorded state, never a boolean a caller can
 * ignore: a theme whose manifest failed any compatibility, confinement
 * or missing-file check is persisted with status `invalid` and the
 * specific reasons, and only a `valid` registration is activatable.
 * Activation itself (T068) must consult this state through the registry.
 */
final readonly class ThemeRegistration
{
    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    /** @param list<string> $errors */
    public function __construct(
        public string $themeId,
        public ?string $name,
        public ?string $version,
        public string $status,
        public array $errors,
        public string $checksum,
        public string $path,
    ) {}

    public function activatable(): bool
    {
        return $this->status === self::STATUS_VALID;
    }

    /** @param object{theme_id: string, name: string|null, version: string|null, status: string, errors: string|null, checksum: string, path: string} $row */
    public static function fromRow(object $row): self
    {
        $errors = [];
        if (is_string($row->errors) && $row->errors !== '') {
            $decoded = json_decode($row->errors, true);
            if (is_array($decoded)) {
                $errors = array_values(array_filter($decoded, 'is_string'));
            }
        }

        return new self(
            themeId: (string) $row->theme_id,
            name: $row->name !== null ? (string) $row->name : null,
            version: $row->version !== null ? (string) $row->version : null,
            status: (string) $row->status,
            errors: $errors,
            checksum: (string) $row->checksum,
            path: (string) $row->path,
        );
    }
}
