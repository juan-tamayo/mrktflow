<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Data;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Amount already claimed under order/refund allocation locks by the caller. */
final readonly class RefundIntent
{
    public function __construct(
        public string $refundId,
        public PaymentIntent $payment,
        public string $captureId,
        public int $amountMinor,
    ) {
        if (! Str::isUuid($refundId) || $refundId !== strtolower($refundId) || $amountMinor < 1 || $amountMinor > $payment->amountMinor) {
            throw new InvalidArgumentException('Invalid refund intent.');
        }
        PaymentIntent::assertReference($captureId);
    }

    public function matches(self $other): bool
    {
        return $this->refundId === $other->refundId && $this->payment->matches($other->payment)
            && $this->captureId === $other->captureId && $this->amountMinor === $other->amountMinor;
    }

    public function idempotencyKey(): string
    {
        return 'refund:'.$this->refundId;
    }
}
