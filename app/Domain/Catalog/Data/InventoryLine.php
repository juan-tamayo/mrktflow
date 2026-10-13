<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class InventoryLine
{
    public function __construct(public string $variantId, public int $quantity)
    {
        if (! Str::isUuid($variantId) || $variantId !== strtolower($variantId) || $quantity < 1 || $quantity > 1000000) {
            throw new InvalidArgumentException('Invalid inventory line.');
        }
    }
}
