<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

final readonly class InventoryReceipt
{
    /** @param list<InventoryAllocation> $allocations */
    public function __construct(
        public string $intentId,
        public array $allocations,
    ) {}
}
