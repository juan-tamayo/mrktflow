<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

final readonly class InventoryAllocation
{
    public function __construct(
        public string $id,
        public string $variantId,
        public int $original,
        public int $shipped,
        public int $released,
        public int $remaining,
        public string $status,
    ) {}
}
