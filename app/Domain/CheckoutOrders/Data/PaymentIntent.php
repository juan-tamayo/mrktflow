<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Data;

use App\Domain\Platform\Support\SetupChoices;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Server-authored frozen total and integration identity; never a browser quote. */
final readonly class PaymentIntent
{
    public function __construct(
        public string $attemptId,
        public int $amountMinor,
        public string $currency,
        public string $accountId,
        public bool $live,
    ) {
        if (! Str::isUuid($attemptId) || $attemptId !== strtolower($attemptId)
            || $amountMinor < 1 || $amountMinor > 9007199254740991
            || ! in_array($currency, array_column((new SetupChoices)->currencies(), 'code'), true)) {
            throw new InvalidArgumentException('Invalid payment intent.');
        }
        self::assertReference($accountId);
    }

    public static function assertReference(string $reference): void
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $reference)) {
            throw new InvalidArgumentException('Invalid provider reference.');
        }
    }

    public function matches(self $other): bool
    {
        return $this->attemptId === $other->attemptId && $this->amountMinor === $other->amountMinor
            && $this->currency === $other->currency && $this->accountId === $other->accountId && $this->live === $other->live;
    }

    public function idempotencyKey(): string
    {
        return 'payment:'.$this->attemptId;
    }
}
