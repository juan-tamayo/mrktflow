<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class PromotionBudgetHold
{
    public function __construct(
        public string $intentId,
        public string $operationKey,
        public string $campaignId,
        public int $revision,
        public int $amount,
        public ?string $couponId,
        public ?string $customerKey,
        public bool $firstCustomer,
        public DateTimeImmutable $expiresAt,
    ) {
        if (! Str::isUuid($this->intentId) || $this->intentId !== strtolower($this->intentId)
            || ! Str::isUuid($this->operationKey) || $this->operationKey !== strtolower($this->operationKey)
            || ! Str::isUuid($this->campaignId) || $this->campaignId !== strtolower($this->campaignId)
            || $this->revision < 1
            || $this->amount < 0 || $this->amount > Money::MAX_SAFE
            || ($this->couponId !== null && (! Str::isUuid($this->couponId) || $this->couponId !== strtolower($this->couponId)))
            || ($this->customerKey !== null && (! Str::isUuid($this->customerKey) || $this->customerKey !== strtolower($this->customerKey)))
            || ! PricedLine::isUtc($this->expiresAt)) {
            throw new InvalidArgumentException('Invalid promotion budget hold.');
        }
    }
}
