<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Policy for staff-entered carrier tracking URLs (FUL-01). Tracking links are
 * rendered to customers in account pages and email, so only absolute
 * http/https URLs on a dotted public hostname are accepted: no other scheme,
 * no scheme-relative URLs, no embedded credentials, no whitespace, control,
 * backslash or quote characters, and no IP-literal or single-label hosts.
 */
final class TrackingUrl implements ValidationRule
{
    public const MAX_LENGTH = 1024;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }
        if (! is_string($value) || self::sanitize($value) === null) {
            $fail('Enter a valid http or https tracking URL.');
        }
    }

    /**
     * Canonical stored form: null for empty input, the trimmed URL when it
     * satisfies the policy; anything else is a validation failure.
     */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw ValidationException::withMessages(['tracking_url' => 'Tracking URL must be text.']);
        }
        $url = self::sanitize($value);
        if ($url === null && trim($value) !== '') {
            throw ValidationException::withMessages(['tracking_url' => 'Enter a valid http or https tracking URL.']);
        }

        return $url;
    }

    /**
     * The URL when it satisfies the tracking policy, null when empty or
     * unsafe. Read paths use this so a value stored before the policy
     * existed can never reach a customer page or email as a link.
     */
    public static function sanitize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $url = trim($value);
        if ($url === '' || mb_strlen($url) > self::MAX_LENGTH) {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7F\\\\<>"\'`]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! self::isPublicHostname($host)) {
            return null;
        }
        if (! self::hasValidPort($url)) {
            return null;
        }

        return $url;
    }

    /**
     * parse_url reports port 0 verbatim and fails outright above 65535, so
     * the range is checked against the raw authority text.
     */
    private static function hasValidPort(string $url): bool
    {
        $authorityStart = strpos($url, '://');
        if ($authorityStart === false) {
            return false;
        }

        $authority = substr($url, $authorityStart + 3);
        $authority = substr($authority, 0, strcspn($authority, '/?#'));
        $hostPart = Str::afterLast($authority, '@');

        if (! str_contains($hostPart, ':')) {
            return true;
        }

        $port = Str::afterLast($hostPart, ':');
        if ($port === '' || ! ctype_digit($port)) {
            return false;
        }

        $portNumber = (int) $port;

        return $portNumber >= 1 && $portNumber <= 65535;
    }

    private static function isPublicHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || ! str_contains($host, '.')) {
            return false;
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }
        $tld = substr($host, (int) strrpos($host, '.') + 1);

        return preg_match('/^[a-z]{2,}$/', $tld) === 1;
    }
}
