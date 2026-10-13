<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Support;

final class HostedCheckoutUrl
{
    public static function parse(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2048) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? null) !== 'checkout.stripe.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $path = is_string($parts['path'] ?? null) ? (string) $parts['path'] : '';
        if (preg_match('#^/(c/)?pay/cs_(test|live)_[A-Za-z0-9]+$#', $path) !== 1) {
            return null;
        }
        if (isset($parts['fragment']) && preg_match('/javascript:/i', (string) $parts['fragment']) === 1) {
            return null;
        }

        return $url;
    }
}
