<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Data;

use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class UsageHold
{
    public function __construct(
        public string $intentId,
        public string $operationKey,
        public string $staffId,
        public int $estimatedTokens,
        public DateTimeImmutable $expiresAt,
    ) {
        foreach ([$intentId, $operationKey, $staffId] as $id) {
            if (! Str::isUuid($id) || $id !== strtolower($id)) {
                throw new InvalidArgumentException('Invalid usage reservation identity.');
            }
        }
        if ($estimatedTokens < 1 || $estimatedTokens > 8192 * 3) {
            throw new InvalidArgumentException('Invalid usage reservation.');
        }
    }
}
