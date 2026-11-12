<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Support;

/**
 * Theme Vite entries declared in reviewed theme.json manifests (T071).
 *
 * Commerce core is not a source of these paths: adding a developer theme
 * only requires confined asset files under that theme directory.
 */
final class DeveloperThemeAssets
{
    /** @return list<string> */
    public static function viteEntries(?string $themesRoot = null): array
    {
        $root = realpath($themesRoot ?? base_path('themes'));
        if ($root === false) {
            return [];
        }
        $names = scandir($root);
        if ($names === false) {
            return [];
        }
        $entries = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $directory = $root.DIRECTORY_SEPARATOR.$name;
            if (! is_dir($directory) || is_link($directory)) {
                continue;
            }
            $manifestPath = $directory.DIRECTORY_SEPARATOR.'theme.json';
            if (! is_file($manifestPath)) {
                continue;
            }
            $raw = file_get_contents($manifestPath);
            if ($raw === false) {
                continue;
            }
            $manifest = json_decode($raw, true);
            $assets = is_array($manifest) ? ($manifest['assets'] ?? null) : null;
            if (! is_array($assets) || array_is_list($assets)) {
                continue;
            }
            foreach ($assets as $value) {
                $relative = self::confinedAsset($name, $value);
                if ($relative === null) {
                    continue;
                }
                $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, substr($relative, strlen('themes/')));
                if (! is_file($absolute)) {
                    continue;
                }
                $entries[] = $relative;
            }
        }
        $entries = array_values(array_unique($entries));
        sort($entries);

        return $entries;
    }

    private static function confinedAsset(string $theme, mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 500
            || str_contains($value, '\\') || str_contains($value, '://')
            || str_starts_with($value, '/')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }
        $relative = str_starts_with($value, 'themes/') ? $value : 'themes/'.$theme.'/'.$value;
        $prefix = 'themes/'.$theme.'/';
        if (! str_starts_with($relative, $prefix)) {
            return null;
        }
        foreach (explode('/', substr($relative, strlen($prefix))) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $relative;
    }
}
