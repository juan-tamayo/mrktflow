<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Platform\Support\PlatformVersion;
use App\Domain\ThemesContent\Contracts\ThemeRegistry;
use App\Domain\ThemesContent\Data\ThemeRegistration;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Installed-theme registry (THEME-01, T061b).
 *
 * Registration is the gate activation (T068) will consult: the manifest
 * is validated completely — structure, core/theme-API compatibility,
 * confined paths, referenced files present on disk — and the outcome is
 * recorded as an explicit `valid`/`invalid` status with the specific
 * reasons. An invalid theme is therefore a durable fact with an audit
 * trail, not a boolean someone can ignore, and no part of validation is
 * deferred to render time.
 *
 * Declared paths may be theme-relative (`views/product.blade.php`) or
 * root-relative (`themes/default/src/product.ts`, the form the installed
 * default theme already uses); root-relative paths must name this theme.
 * Every path is normalized into the theme directory: traversal segments,
 * absolute paths, schemes, remote entries and symlink escapes are
 * refused, and a referenced file that does not exist fails registration
 * naming the template/asset and the missing file.
 *
 * Structural failures that leave nothing recordable — no directory, no
 * manifest, malformed JSON, no usable theme id, or a theme id already
 * owned by a different directory — throw instead of recording.
 */
final class RegisterTheme implements ThemeRegistry
{
    private const MANIFEST_FILE = 'theme.json';

    private const MANIFEST_MAX_BYTES = 65536;

    /** @var list<string> */
    private const ALLOWED_FIELDS = ['schemaVersion', 'id', 'version', 'name', 'requires', 'templates', 'sections', 'assets'];

    private const SUPPORTED_SCHEMA_VERSION = '1.0';

    public function __construct(private readonly ?string $themesRoot = null) {}

    public function register(string $themeDirectory): ThemeRegistration
    {
        $root = $this->root();
        $directory = realpath($themeDirectory);
        if ($directory === false || ! is_dir($directory)) {
            throw new InvalidArgumentException('Theme directory does not exist.');
        }
        if ($directory === $root || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Theme directory must be inside the themes root.');
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($directory, strlen($root) + 1));

        $manifestPath = $directory.DIRECTORY_SEPARATOR.self::MANIFEST_FILE;
        $manifestReal = realpath($manifestPath);
        if ($manifestReal === false || ! is_file($manifestReal)) {
            throw new InvalidArgumentException('Theme manifest theme.json is missing.');
        }
        if (! str_starts_with($manifestReal, $directory.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Theme manifest must live inside the theme directory.');
        }
        if (filesize($manifestReal) > self::MANIFEST_MAX_BYTES) {
            throw new InvalidArgumentException('Theme manifest exceeds the size limit.');
        }
        $raw = file_get_contents($manifestReal);
        if ($raw === false) {
            throw new InvalidArgumentException('Theme manifest cannot be read.');
        }
        $manifest = json_decode($raw, true);
        if (! is_array($manifest) || array_is_list($manifest)) {
            throw new InvalidArgumentException('Theme manifest must be a JSON object.');
        }
        $themeId = $manifest['id'] ?? null;
        if (! is_string($themeId) || $themeId === '' || strlen($themeId) > 191) {
            throw new InvalidArgumentException('Theme manifest must declare a usable string id before it can be registered.');
        }

        $errors = $this->validate($manifest, $directory, $root, $relative);
        $status = $errors === [] ? ThemeRegistration::STATUS_VALID : ThemeRegistration::STATUS_INVALID;
        $checksum = hash('sha256', $raw);
        $name = is_string($manifest['name'] ?? null) ? $manifest['name'] : null;
        $version = is_string($manifest['version'] ?? null) ? $manifest['version'] : null;
        $schemaVersion = is_string($manifest['schemaVersion'] ?? null) ? $manifest['schemaVersion'] : null;

        DB::transaction(function () use ($themeId, $relative, $raw, $checksum, $status, $errors, $name, $version, $schemaVersion): void {
            $existing = DB::table('installed_themes')->where('theme_id', $themeId)->lockForUpdate()->first();
            if ($existing !== null && (string) $existing->path !== $relative) {
                throw new DomainException("Theme id '{$themeId}' is already registered from '{$existing->path}'.");
            }
            $values = [
                'name' => $name,
                'version' => $version,
                'schema_version' => $schemaVersion,
                'path' => $relative,
                'manifest' => $raw,
                'checksum' => $checksum,
                'status' => $status,
                'errors' => $errors === [] ? null : json_encode($errors, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ];
            if ($existing === null) {
                DB::table('installed_themes')->insert($values + [
                    'id' => (string) Str::uuid(),
                    'theme_id' => $themeId,
                    'created_at' => now(),
                ]);
            } else {
                DB::table('installed_themes')->where('theme_id', $themeId)->update($values);
            }
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:theme-registration:'.(string) Str::uuid(), [
                'action' => 'theme.registered',
                'subject_type' => 'theme',
                'subject_id' => $themeId,
                'actor_type' => 'system',
                'actor_id' => null,
                'metadata' => ['status' => $status, 'error_count' => count($errors), 'checksum' => $checksum],
            ]);
        });

        return new ThemeRegistration(
            themeId: $themeId,
            name: $name,
            version: $version,
            status: $status,
            errors: $errors,
            checksum: $checksum,
            path: $relative,
        );
    }

    public function find(string $themeId): ?ThemeRegistration
    {
        /** @var object{theme_id: string, name: string|null, version: string|null, status: string, errors: string|null, checksum: string, path: string}|null $row */
        $row = DB::table('installed_themes')->where('theme_id', $themeId)->first();

        return $row === null ? null : ThemeRegistration::fromRow($row);
    }

    public function isActivatable(string $themeId): bool
    {
        $status = DB::table('installed_themes')->where('theme_id', $themeId)->value('status');

        return $status === ThemeRegistration::STATUS_VALID;
    }

    private function root(): string
    {
        $root = realpath($this->themesRoot ?? base_path('themes'));
        if ($root === false) {
            throw new LogicException('Themes root does not exist.');
        }

        return $root;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function validate(array $manifest, string $directory, string $root, string $relative): array
    {
        $errors = [];
        foreach (array_keys($manifest) as $field) {
            if (! in_array((string) $field, self::ALLOWED_FIELDS, true)) {
                $errors[] = "Unknown manifest field '{$field}'.";
            }
        }
        if (($manifest['schemaVersion'] ?? null) !== self::SUPPORTED_SCHEMA_VERSION) {
            $errors[] = "Unsupported manifest schemaVersion; this platform reads '".self::SUPPORTED_SCHEMA_VERSION."'.";
        }
        $themeId = $manifest['id'];
        if (! is_string($themeId) || ! preg_match('/^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9._-]*$/D', $themeId)) {
            $errors[] = 'Theme id must be a vendor/name identifier.';
        }
        $version = $manifest['version'] ?? null;
        if (! is_string($version) || ! preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
            $errors[] = 'Theme version must be a semantic version (x.y.z).';
        }
        $name = $manifest['name'] ?? null;
        if (! is_string($name) || trim($name) === '' || strlen($name) > 120) {
            $errors[] = 'Theme name must be a non-empty string of at most 120 characters.';
        }
        array_push($errors, ...$this->validateRequires($manifest['requires'] ?? null));
        array_push($errors, ...$this->validateTemplates($manifest['templates'] ?? null, $directory, $root, $relative));
        array_push($errors, ...$this->validateSections($manifest['sections'] ?? null));
        array_push($errors, ...$this->validateAssets($manifest['assets'] ?? null, $directory, $root, $relative));

        return $errors;
    }

    /** @return list<string> */
    private function validateRequires(mixed $requires): array
    {
        if (! is_array($requires) || array_is_list($requires)) {
            return ['Manifest requires must be an object declaring themeApi and an optional core range.'];
        }
        $errors = [];
        foreach (array_keys($requires) as $field) {
            if (! in_array((string) $field, ['core', 'themeApi'], true)) {
                $errors[] = "Unknown requires field '{$field}'.";
            }
        }
        if (! array_key_exists('themeApi', $requires)) {
            $errors[] = 'Manifest requires must declare themeApi.';
        } elseif (! is_string($requires['themeApi']) || $requires['themeApi'] === '') {
            $errors[] = 'requires.themeApi must be a version range string.';
        } else {
            try {
                if (! PlatformVersion::themeApiSatisfies($requires['themeApi'])) {
                    $errors[] = "Theme requires themeApi '{$requires['themeApi']}' but the platform theme API is ".PlatformVersion::THEME_API_VERSION.'.';
                }
            } catch (InvalidArgumentException) {
                $errors[] = "Theme requires themeApi '{$requires['themeApi']}' which the platform cannot evaluate.";
            }
        }
        if (array_key_exists('core', $requires)) {
            if (! is_string($requires['core']) || $requires['core'] === '') {
                $errors[] = 'requires.core must be a version range string.';
            } else {
                try {
                    if (! PlatformVersion::coreSatisfies($requires['core'])) {
                        $errors[] = "Theme requires core '{$requires['core']}' but the platform core is ".PlatformVersion::CORE_VERSION.'.';
                    }
                } catch (InvalidArgumentException) {
                    $errors[] = "Theme requires core '{$requires['core']}' which the platform cannot evaluate.";
                }
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateTemplates(mixed $templates, string $directory, string $root, string $relative): array
    {
        if (! is_array($templates) || $templates === [] || array_is_list($templates)) {
            return ['Manifest templates must be a non-empty object of template key to path.'];
        }
        if (count($templates) > 50) {
            return ['Manifest templates may declare at most 50 templates.'];
        }
        $errors = [];
        foreach ($templates as $key => $value) {
            $key = (string) $key;
            if (! preg_match('/^[a-z][a-z0-9._-]*$/D', $key)) {
                $errors[] = "Template key '{$key}' is invalid.";

                continue;
            }
            if (! is_string($value)) {
                $errors[] = "Template '{$key}' must declare a path string.";

                continue;
            }
            $error = $this->confinedPathError($directory, $root, $relative, $value, "Template '{$key}'", ['.blade.php']);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateSections(mixed $sections): array
    {
        if ($sections === null) {
            return [];
        }
        if (! is_array($sections) || ! array_is_list($sections) || count($sections) > 100) {
            return ['Manifest sections must be a list of at most 100 section types.'];
        }
        $errors = [];
        $seen = [];
        foreach ($sections as $section) {
            if (! is_string($section) || ! preg_match('/^[a-z][a-z0-9-]*$/D', $section)) {
                $errors[] = 'Manifest sections must name section types like hero or product-grid.';

                continue;
            }
            if (isset($seen[$section])) {
                $errors[] = "Manifest sections repeats '{$section}'.";
            }
            $seen[$section] = true;
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateAssets(mixed $assets, string $directory, string $root, string $relative): array
    {
        if ($assets === null) {
            return [];
        }
        if (! is_array($assets) || $assets === [] || array_is_list($assets)) {
            return ['Manifest assets must be a non-empty object of asset key to path.'];
        }
        $suffixes = ['entry' => ['.ts', '.js', '.vue'], 'style' => ['.css']];
        $errors = [];
        foreach ($assets as $key => $value) {
            $key = (string) $key;
            if (! isset($suffixes[$key])) {
                $errors[] = "Unknown asset key '{$key}'.";

                continue;
            }
            if (! is_string($value)) {
                $errors[] = "Asset '{$key}' must declare a path string.";

                continue;
            }
            $error = $this->confinedPathError($directory, $root, $relative, $value, "Asset '{$key}'", $suffixes[$key]);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $suffixes
     */
    private function confinedPathError(string $directory, string $root, string $relative, string $declared, string $label, array $suffixes): ?string
    {
        if (strlen($declared) > 500
            || str_contains($declared, '\\')
            || str_contains($declared, '://')
            || preg_match('/[\x00-\x1F\x7F]/', $declared) === 1
            || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $declared) === 1
            || str_starts_with($declared, '/')) {
            return "{$label} path '{$declared}' must be a confined relative path inside the theme directory.";
        }
        $path = $declared;
        $rootPrefix = basename($root).'/'.$relative.'/';
        if (str_starts_with($path, $rootPrefix)) {
            $path = substr($path, strlen($rootPrefix));
        } elseif (str_starts_with($path, basename($root).'/')) {
            return "{$label} path '{$declared}' points outside this theme directory.";
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return "{$label} path '{$declared}' escapes the theme directory.";
            }
        }
        $suffixOk = false;
        foreach ($suffixes as $suffix) {
            if (str_ends_with($path, $suffix)) {
                $suffixOk = true;
                break;
            }
        }
        if (! $suffixOk) {
            return "{$label} path '{$declared}' must end with ".implode(' or ', $suffixes).'.';
        }
        $candidate = $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (! is_file($candidate)) {
            return "{$label} references missing file '{$path}'.";
        }
        $real = realpath($candidate);
        if ($real === false || ! str_starts_with($real, $directory.DIRECTORY_SEPARATOR)) {
            return "{$label} path '{$declared}' escapes the theme directory through a link.";
        }

        return null;
    }
}
