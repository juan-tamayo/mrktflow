<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Data;

use App\Domain\Identity\Models\CustomerAccount;

/**
 * Who is asking for a download (FUL-05). Exactly one scope is set: a
 * signed-in customer account, or the verified guest grant for a single
 * order. The session id binds transfer leases to the browser session that
 * started them; it never widens the principal's scope.
 */
final readonly class DownloadPrincipal
{
    public function __construct(
        public ?string $customerId,
        public ?string $orderId,
        public string $sessionId,
    ) {}

    public static function account(CustomerAccount $customer, string $sessionId): self
    {
        return new self($customer->id, null, $sessionId);
    }

    public static function guestOrder(string $orderId, string $sessionId): self
    {
        return new self(null, $orderId, $sessionId);
    }

    public function isAccount(): bool
    {
        return $this->customerId !== null;
    }
}
