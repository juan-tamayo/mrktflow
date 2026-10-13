<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Contracts;

use App\Domain\Catalog\Data\InventoryHold;
use App\Domain\Catalog\Data\InventoryMutation;
use App\Domain\Catalog\Data\InventoryReceipt;

interface InventoryAllocator
{
    public function hold(InventoryHold $hold): InventoryReceipt;

    public function commit(string $intentId, string $operationKey): InventoryReceipt;

    public function release(InventoryMutation $mutation): InventoryReceipt;

    public function ship(InventoryMutation $mutation): InventoryReceipt;
}
