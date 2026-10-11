<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Data;

use InvalidArgumentException;

/** Normalized authenticated provider evidence, not a redirect or unverified webhook. */
final readonly class PaymentSession
{
    private function __construct(
        public ?string $sessionId,
        public string $state,
        public string $paymentStatus,
        public ?int $expiresAt,
        public ?string $captureId,
    ) {}

    public static function unknown(): self
    {
        return new self(null, 'unknown', 'unknown', null, null);
    }

    public static function observed(PaymentIntent $expected, PaymentIntent $actual, string $sessionId, string $state, string $paymentStatus, int $expiresAt, ?string $captureId): self
    {
        PaymentIntent::assertReference($sessionId);
        if ($captureId !== null) {
            PaymentIntent::assertReference($captureId);
        }
        if (! $expected->matches($actual) || ! in_array($state, ['open', 'complete', 'expired'], true)
            || ! in_array($paymentStatus, ['paid', 'unpaid'], true) || $expiresAt < 1
            || ($paymentStatus === 'paid' && ($state !== 'complete' || $captureId === null))
            || ($paymentStatus === 'unpaid' && $captureId !== null)) {
            throw new InvalidArgumentException('Unbound or inconsistent payment evidence.');
        }

        return new self($sessionId, $state, $paymentStatus, $expiresAt, $captureId);
    }

    public function isPaid(): bool
    {
        return $this->state === 'complete' && $this->paymentStatus === 'paid';
    }

    public function mayReleaseReservation(): bool
    {
        return $this->state === 'expired' && $this->paymentStatus === 'unpaid';
    }
}
