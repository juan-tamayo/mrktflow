<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class InventoryHold
{
    /** @param list<InventoryLine> $lines */
    public function __construct(
        public string $intentId,
        public string $operationKey,
        public DateTimeImmutable $expiresAt,
        public array $lines,
    ) {
        if (! Str::isUuid($intentId) || $intentId !== strtolower($intentId)
            || ! Str::isUuid($operationKey) || $operationKey !== strtolower($operationKey)
            || $lines === []) {
            throw new InvalidArgumentException('Invalid inventory hold.');
        }
        $seen = [];
        foreach ($lines as $line) {
            if (isset($seen[$line->variantId])) {
                throw new InvalidArgumentException('Invalid inventory hold.');
            }
            $seen[$line->variantId] = true;
        }
    }
}
