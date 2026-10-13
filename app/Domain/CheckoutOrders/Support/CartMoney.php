<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Support;

use InvalidArgumentException;

final class CartMoney
{
    public const MAX_SAFE = 9007199254740991;

    public const MAX_QUANTITY = 99;

    /**
     * @return array{amount: int, currency: string, exponent: int, formatted: string}
     */
    public static function format(int $amount, string $currency, int $exponent): array
    {
        if ($amount < 0 || $amount > self::MAX_SAFE || $exponent < 0 || $exponent > 3 || $currency === '') {
            throw new InvalidArgumentException('Unsupported money.');
        }
        $scale = 10 ** $exponent;
        $fraction = $exponent === 0 ? '' : '.'.str_pad((string) ($amount % $scale), $exponent, '0', STR_PAD_LEFT);

        return [
            'amount' => $amount,
            'currency' => $currency,
            'exponent' => $exponent,
            'formatted' => $currency.' '.intdiv($amount, $scale).$fraction,
        ];
    }

    public static function times(int $amount, int $quantity): int
    {
        if ($quantity < 0 || $quantity > self::MAX_QUANTITY || $amount < 0 || $amount > self::MAX_SAFE) {
            throw new InvalidArgumentException('Unsupported quantity.');
        }
        if ($quantity !== 0 && $amount > intdiv(self::MAX_SAFE, $quantity)) {
            throw new InvalidArgumentException('Unsupported total.');
        }

        return $amount * $quantity;
    }
}
