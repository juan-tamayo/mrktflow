<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Support;

use App\Domain\Pricing\Rules\AllocateDiscount;
use InvalidArgumentException;

final class RefundAllocator
{
    /**
     * @return list<int>
     */
    public function unitAmounts(int $total, int $quantity): array
    {
        return (new AllocateDiscount)->splitAcrossUnits($total, $quantity);
    }

    /**
     * @param  list<int>  $units
     */
    public function slice(array $units, int $start, int $count): int
    {
        if ($start < 0 || $count < 1 || $start + $count > count($units)) {
            throw new InvalidArgumentException('Refund units exceed the original line.');
        }
        $amount = 0;
        for ($index = 0; $index < $count; $index++) {
            $amount += $units[$start + $index];
        }

        return $amount;
    }
}
