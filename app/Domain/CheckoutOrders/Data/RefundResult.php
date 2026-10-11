<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Data;

use InvalidArgumentException;

final readonly class RefundResult
{
    private function __construct(public ?string $providerRefundId, public string $status) {}

    public static function unknown(): self
    {
        return new self(null, 'unknown');
    }

    public static function observed(RefundIntent $expected, RefundIntent $actual, string $providerRefundId, string $status): self
    {
        PaymentIntent::assertReference($providerRefundId);
        if (! $expected->matches($actual) || ! in_array($status, ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true)) {
            throw new InvalidArgumentException('Unbound or inconsistent refund evidence.');
        }

        return new self($providerRefundId, $status);
    }

    public function isSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function mayReleaseAllocation(): bool
    {
        return in_array($this->status, ['failed', 'canceled'], true);
    }
}
