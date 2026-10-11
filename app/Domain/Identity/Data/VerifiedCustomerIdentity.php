<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

/** Server-side result for use inside the proving transaction; never a bearer credential. */
final readonly class VerifiedCustomerIdentity
{
    public function __construct(public string $customerId, public string $email) {}
}
