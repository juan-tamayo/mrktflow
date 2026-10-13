<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use JsonSerializable;

// The authenticator secret and recovery codes are returned once; never serialize or log this object.
final readonly class TwoFactorEnrollment implements JsonSerializable
{
    /** @param list<string> $recoveryCodes */
    public function __construct(
        public string $secret,
        public string $uri,
        public string $issuer,
        public string $label,
        public array $recoveryCodes = [],
    ) {}

    /** @return array{issuer: string, label: string} */
    public function jsonSerialize(): array
    {
        return ['issuer' => $this->issuer, 'label' => $this->label];
    }
}
