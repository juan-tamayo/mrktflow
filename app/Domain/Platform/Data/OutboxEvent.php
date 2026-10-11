<?php

declare(strict_types=1);

namespace App\Domain\Platform\Data;

final readonly class OutboxEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $type,
        public int $schemaVersion,
        public array $payload,
        public string $correlationId,
        public string $occurredAt,
    ) {}
}
