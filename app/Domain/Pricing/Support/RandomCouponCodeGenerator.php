<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Support;

use App\Domain\Pricing\Contracts\CouponCodeGenerator;

final class RandomCouponCodeGenerator implements CouponCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function next(): string
    {
        $alphabet = self::ALPHABET;
        $length = 12;
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }
}
