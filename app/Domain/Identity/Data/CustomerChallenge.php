<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use Carbon\CarbonImmutable;
use JsonSerializable;
use LogicException;

final readonly class CustomerChallenge implements JsonSerializable
{
    public function __construct(public string $id, public string $purpose, #[\SensitiveParameter] private string $secret, public CarbonImmutable $expiresAt) {}

    // Application delivery layer only. Never expose the credential through a response or log.
    public function token(): string
    {
        return $this->secret;
    }

    /** @return array{id: string, purpose: string, expires_at: string} */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'purpose' => $this->purpose, 'expires_at' => $this->expiresAt->toIso8601String()];
    }

    /** @return array{id: string, purpose: string, expires_at: string} */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException('Customer challenge credentials must not be serialized.');
    }
}
