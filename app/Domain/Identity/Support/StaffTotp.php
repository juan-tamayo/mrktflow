<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use InvalidArgumentException;
use OTPHP\TOTP;

final class StaffTotp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    public const DIGEST = 'sha1';

    public const SECRET_BYTES = 20;

    public const WINDOW_PERIODS = 1;

    public const PENDING_MINUTES = 15;

    public const REAUTH_MINUTES = 15;

    public const RECOVERY_COUNT = 10;

    public static function generate(): TOTP
    {
        return self::configure(TOTP::generate(new StaffClock, self::SECRET_BYTES));
    }

    public static function make(string $secret): TOTP
    {
        if ($secret === '') {
            throw new InvalidArgumentException('Authenticator secret is missing.');
        }

        return self::configure(TOTP::createFromSecret($secret, new StaffClock));
    }

    public static function code(string $secret, ?int $timestamp = null): string
    {
        $at = $timestamp ?? now()->getTimestamp();

        return self::make($secret)->at($at >= 0 ? $at : 0);
    }

    public static function matchingStep(string $secret, string $code, ?int $timestamp = null): ?int
    {
        if (! preg_match('/^\d{6}$/D', $code)) {
            return null;
        }
        $at = $timestamp ?? now()->getTimestamp();
        $totp = self::make($secret);
        for ($delta = -self::WINDOW_PERIODS; $delta <= self::WINDOW_PERIODS; $delta++) {
            $candidate = $at + ($delta * self::PERIOD);
            if ($candidate < 0) {
                continue;
            }
            if (hash_equals($totp->at($candidate), $code)) {
                return intdiv($candidate, self::PERIOD);
            }
        }

        return null;
    }

    private static function configure(TOTP $totp): TOTP
    {
        $totp->setDigits(self::DIGITS);
        $totp->setPeriod(self::PERIOD);
        $totp->setDigest(self::DIGEST);

        return $totp;
    }
}
