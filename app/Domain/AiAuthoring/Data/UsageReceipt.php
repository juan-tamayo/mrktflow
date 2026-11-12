<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Data;

final readonly class UsageReceipt
{
    public function __construct(
        public string $intentId,
        public string $reservationId,
        public string $status,
    ) {}
}
