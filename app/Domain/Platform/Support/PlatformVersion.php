<?php

declare(strict_types=1);

namespace App\Domain\Platform\Support;

use InvalidArgumentException;

/**
 * The platform's own version facts (T061a).
 *
 * Until now the platform never versioned itself in code: the installer
 * records install state, but nothing a theme manifest could be checked
 * against. This class is the single source for the two facts developer
 * themes declare requirements on — the core release and the theme API
 * major — plus the bounded range evaluation both checks share.
 *
 * Range grammar is deliberately small: a whitespace-separated list of
 * comparator tokens (`>=`, `<=`, `>`, `<`, `=`) that must all hold, or
 * bare partial versions where `1` means `>=1.0.0 <2.0.0`, `1.2` means
 * `>=1.2.0 <1.3.0` and a full `1.2.3` pins exactly. Anything outside the
 * grammar is unparsable and throws, so a manifest can never smuggle an
 * unevaluated requirement past registration.
 */
final class PlatformVersion
{
    public const CORE_VERSION = '1.0.0';

    public const THEME_API_VERSION = 1;

    public static function coreSatisfies(string $requirement): bool
    {
        return self::satisfies($requirement, self::CORE_VERSION);
    }

    public static function themeApiSatisfies(string $requirement): bool
    {
        return self::satisfies($requirement, self::THEME_API_VERSION.'.0.0');
    }

    public static function satisfies(string $requirement, string $actual): bool
    {
        $requirement = trim($requirement);
        if ($requirement === '') {
            throw new InvalidArgumentException('A version requirement cannot be empty.');
        }
        $actual = self::normalize($actual);
        foreach (preg_split('/\s+/', $requirement) ?: [] as $token) {
            if (! self::tokenSatisfies($token, $actual)) {
                return false;
            }
        }

        return true;
    }

    private static function tokenSatisfies(string $token, string $actual): bool
    {
        if (! preg_match('/^(>=|<=|>|<|==|=)?v?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/D', $token, $matches)) {
            throw new InvalidArgumentException("Unparsable version requirement token '{$token}'.");
        }
        $operator = $matches[1];
        $major = (int) $matches[2];
        $minor = isset($matches[3]) ? (int) $matches[3] : null;
        $patch = isset($matches[4]) ? (int) $matches[4] : null;

        if ($operator === '') {
            // Bare partial versions pin the precision they declare.
            if ($minor === null) {
                return version_compare($actual, "{$major}.0.0", '>=') && version_compare($actual, ($major + 1).'.0.0', '<');
            }
            if ($patch === null) {
                return version_compare($actual, "{$major}.{$minor}.0", '>=') && version_compare($actual, $major.'.'.($minor + 1).'.0', '<');
            }

            return version_compare($actual, "{$major}.{$minor}.{$patch}", '=');
        }

        $target = $major.'.'.($minor ?? 0).'.'.($patch ?? 0);

        return version_compare($actual, $target, $operator === '==' ? '=' : $operator);
    }

    private static function normalize(string $version): string
    {
        if (! preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/D', trim($version), $matches)) {
            throw new InvalidArgumentException("Unparsable platform version '{$version}'.");
        }

        return ((int) $matches[1]).'.'.((int) ($matches[2] ?? 0)).'.'.((int) ($matches[3] ?? 0));
    }
}
