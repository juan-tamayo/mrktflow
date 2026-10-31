<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Data;

use App\Domain\Platform\Support\SetupChoices;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Authenticated provider refund evidence from the durable inbox; never browser input. */
final readonly class ExternalRefundObservation
{
    public function __construct(
        public string $providerRefundId,
        public string $captureReference,
        public int $amountMinor,
        public string $currency,
        public string $providerStatus,
        public ?string $providerReason,
        public ?string $localRefundId,
    ) {
        PaymentIntent::assertReference($providerRefundId);
        PaymentIntent::assertReference($captureReference);
        if ($amountMinor < 1 || $amountMinor > 9007199254740991
            || ! in_array($currency, array_column((new SetupChoices)->currencies(), 'code'), true)
            || ! in_array($providerStatus, ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true)
            || ($providerReason !== null && preg_match('/\A[a-z_]{1,64}\z/', $providerReason) !== 1)
            || ($localRefundId !== null && (! Str::isUuid($localRefundId) || $localRefundId !== strtolower($localRefundId)))) {
            throw new InvalidArgumentException('Invalid external refund observation.');
        }
    }

    public function localStatus(): string
    {
        return match ($this->providerStatus) {
            'succeeded' => 'succeeded',
            'failed', 'canceled' => 'failed',
            default => 'processing',
        };
    }
}
