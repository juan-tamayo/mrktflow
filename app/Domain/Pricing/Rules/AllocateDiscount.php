<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Rules;

use App\Domain\Pricing\Data\Money;
use App\Domain\Pricing\Exceptions\PricingInvalidCoupon;
use App\Domain\Pricing\Exceptions\PricingInvalidMoney;

final class AllocateDiscount
{
    /**
     * @param  array<string, int>  $eligibleSubtotals
     * @return array<string, int>
     */
    public function allocate(array $eligibleSubtotals, int $discount): array
    {
        if ($discount < 0 || $discount > Money::MAX_SAFE) {
            throw new PricingInvalidCoupon;
        }
        $shares = [];
        $total = 0;
        foreach ($eligibleSubtotals as $lineId => $subtotal) {
            if ($subtotal < 0 || $subtotal > Money::MAX_SAFE) {
                throw new PricingInvalidCoupon;
            }
            $shares[$lineId] = 0;
            if ($total > Money::MAX_SAFE - $subtotal) {
                throw new PricingInvalidCoupon;
            }
            $total += $subtotal;
        }
        if ($discount === 0) {
            return $shares;
        }
        if ($total === 0 || $discount > $total) {
            throw new PricingInvalidCoupon;
        }
        $assigned = 0;
        $remainders = [];
        foreach ($eligibleSubtotals as $lineId => $subtotal) {
            $product = $this->multiply($this->intDigits($discount), $this->intDigits($subtotal));
            $base = (int) $this->divide($product, $this->intDigits($total));
            $remainders[$lineId] = (int) $this->modulo($product, $this->intDigits($total));
            $shares[$lineId] = $base;
            $assigned += $base;
        }
        $leftover = $discount - $assigned;
        $order = array_keys($remainders);
        usort($order, function (string $left, string $right) use ($remainders): int {
            $compare = $remainders[$right] <=> $remainders[$left];

            return $compare !== 0 ? $compare : strcmp($left, $right);
        });
        foreach ($order as $lineId) {
            if ($leftover === 0) {
                break;
            }
            $shares[$lineId]++;
            $leftover--;
        }

        return $shares;
    }

    /**
     * @return list<int>
     */
    public function splitAcrossUnits(int $discount, int $quantity): array
    {
        if ($quantity < 1 || $discount < 0) {
            throw new PricingInvalidCoupon;
        }
        $base = intdiv($discount, $quantity);
        $extra = $discount % $quantity;
        $units = [];
        for ($index = 0; $index < $quantity; $index++) {
            $units[] = $base + ($index >= $quantity - $extra ? 1 : 0);
        }

        return $units;
    }

    public function percentageOf(int $subtotal, int $numerator, int $denominator): int
    {
        if ($subtotal < 0 || $numerator < 1 || $denominator < 1 || $numerator > $denominator) {
            throw new PricingInvalidCoupon;
        }
        $product = $this->multiply($this->intDigits($subtotal), $this->intDigits($numerator));
        $half = $this->divide($this->intDigits($denominator), '2');
        $amount = (int) $this->divide($this->add($product, $half), $this->intDigits($denominator));
        if ($amount > $subtotal) {
            throw new PricingInvalidCoupon;
        }

        return $amount;
    }

    /**
     * @param  numeric-string  $left
     * @param  numeric-string  $right
     * @return numeric-string
     */
    private function multiply(string $left, string $right): string
    {
        return bcmul($left, $right, 0);
    }

    /**
     * @param  numeric-string  $left
     * @param  numeric-string  $right
     * @return numeric-string
     */
    private function add(string $left, string $right): string
    {
        return bcadd($left, $right, 0);
    }

    /**
     * @param  numeric-string  $left
     * @param  numeric-string  $right
     * @return numeric-string
     */
    private function divide(string $left, string $right): string
    {
        return bcdiv($left, $right, 0);
    }

    /**
     * @param  numeric-string  $left
     * @param  numeric-string  $right
     * @return numeric-string
     */
    private function modulo(string $left, string $right): string
    {
        return bcmod($left, $right);
    }

    /** @return numeric-string */
    private function intDigits(int $value): string
    {
        if ($value < 0) {
            throw new PricingInvalidMoney;
        }

        return sprintf('%d', $value);
    }
}
