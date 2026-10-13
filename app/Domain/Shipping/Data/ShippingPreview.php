<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Data;

final readonly class ShippingPreview
{
    /**
     * @param  list<array{label: string, type: string, amount: int|null, offered: bool, reason: string|null}>  $methods
     */
    public function __construct(
        public string $zoneId,
        public string $zoneName,
        public int $priority,
        public array $methods,
    ) {}

    /** @return array{zoneId: string, zoneName: string, priority: int, methods: list<array{label: string, type: string, amount: int|null, offered: bool, reason: string|null}>} */
    public function toArray(): array
    {
        return [
            'zoneId' => $this->zoneId,
            'zoneName' => $this->zoneName,
            'priority' => $this->priority,
            'methods' => $this->methods,
        ];
    }
}
