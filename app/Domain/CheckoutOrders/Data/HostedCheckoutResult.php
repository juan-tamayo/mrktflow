<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Data;

final readonly class HostedCheckoutResult
{
    public function __construct(
        public PaymentSession $session,
        public ?string $hostedUrl,
    ) {}
}
