<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

final readonly class PromotionOffer
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $status,
        public string $reasonCode,
        public string $reason,
        public ?string $code = null,
        public ?string $name = null,
    ) {}

    /** @return array{id: string, kind: string, status: string, reason_code: string, reason: string, code: ?string, name: ?string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'reason_code' => $this->reasonCode,
            'reason' => $this->reason,
            'code' => $this->code,
            'name' => $this->name,
        ];
    }
}
