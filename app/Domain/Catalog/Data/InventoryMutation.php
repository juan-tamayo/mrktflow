<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class InventoryMutation
{
    /** @param list<InventoryLine> $lines */
    public function __construct(
        public string $intentId,
        public string $operationKey,
        public array $lines,
    ) {
        if (! Str::isUuid($intentId) || $intentId !== strtolower($intentId)
            || ! Str::isUuid($operationKey) || $operationKey !== strtolower($operationKey)
            || $lines === []) {
            throw new InvalidArgumentException('Invalid inventory mutation.');
        }
        $seen = [];
        foreach ($lines as $line) {
            if (isset($seen[$line->variantId])) {
                throw new InvalidArgumentException('Invalid inventory mutation.');
            }
            $seen[$line->variantId] = true;
        }
    }
}
