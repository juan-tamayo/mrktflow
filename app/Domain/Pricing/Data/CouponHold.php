<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CouponHold
{
    public function __construct(
        public string $intentId,
        public string $operationKey,
        public string $couponId,
        public int $revision,
        public ?string $customerKey,
        public DateTimeImmutable $expiresAt,
    ) {
        if (! Str::isUuid($this->intentId) || $this->intentId !== strtolower($this->intentId)
            || ! Str::isUuid($this->operationKey) || $this->operationKey !== strtolower($this->operationKey)
            || ! Str::isUuid($this->couponId) || $this->couponId !== strtolower($this->couponId)
            || $this->revision < 1
            || ($this->customerKey !== null && (! Str::isUuid($this->customerKey) || $this->customerKey !== strtolower($this->customerKey)))
            || ! PricedLine::isUtc($this->expiresAt)) {
            throw new InvalidArgumentException('Invalid coupon hold.');
        }
    }
}
