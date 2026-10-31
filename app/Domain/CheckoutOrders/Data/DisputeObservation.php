<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Data;

use App\Domain\Platform\Support\SetupChoices;
use InvalidArgumentException;

/** Authenticated provider dispute evidence from the durable inbox; never browser input. */
final readonly class DisputeObservation
{
    public function __construct(
        public string $providerDisputeId,
        public string $captureReference,
        public int $amountMinor,
        public string $currency,
        public string $status,
        public ?string $reason,
    ) {
        PaymentIntent::assertReference($providerDisputeId);
        PaymentIntent::assertReference($captureReference);
        if ($amountMinor < 1 || $amountMinor > 9007199254740991
            || ! in_array($currency, array_column((new SetupChoices)->currencies(), 'code'), true)
            || ! in_array($status, ['opened', 'won', 'lost'], true)
            || ($reason !== null && preg_match('/\A[a-z_]{1,64}\z/', $reason) !== 1)) {
            throw new InvalidArgumentException('Invalid dispute observation.');
        }
    }
}
