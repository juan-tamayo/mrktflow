<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use Carbon\CarbonImmutable;
use JsonSerializable;

// The token is returned once to the application delivery layer; never serialize or log this object.
final readonly class StaffInvitation implements JsonSerializable
{
    public function __construct(public string $id, public string $token, public CarbonImmutable $expiresAt) {}

    /** @return array{id: string, expires_at: string} */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'expires_at' => $this->expiresAt->toIso8601String()];
    }
}
