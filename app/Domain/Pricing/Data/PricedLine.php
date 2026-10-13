<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

use App\Domain\Pricing\Exceptions\PricingInvalidQuote;
use DateTimeImmutable;
use Illuminate\Support\Str;

final readonly class PricedLine
{
    public function __construct(
        public string $lineId,
        public string $variantId,
        public int $quantity,
        public Money $regular,
        public ?Money $sale = null,
        public ?DateTimeImmutable $saleStartsAt = null,
        public ?DateTimeImmutable $saleEndsAt = null,
    ) {
        if (! Str::isUuid($this->lineId) || $this->lineId !== strtolower($this->lineId)
            || ! Str::isUuid($this->variantId) || $this->variantId !== strtolower($this->variantId)
            || $this->quantity < 1 || $this->quantity > 1_000_000) {
            throw new PricingInvalidQuote;
        }
        if ($this->sale === null) {
            if ($this->saleStartsAt !== null || $this->saleEndsAt !== null) {
                throw new PricingInvalidQuote;
            }

            return;
        }
        if ($this->saleStartsAt === null || $this->saleEndsAt === null
            || ! self::isUtc($this->saleStartsAt) || ! self::isUtc($this->saleEndsAt)
            || $this->saleStartsAt >= $this->saleEndsAt
            || $this->sale->currency !== $this->regular->currency
            || $this->sale->exponent !== $this->regular->exponent
            || $this->sale->amount > $this->regular->amount) {
            throw new PricingInvalidQuote;
        }
    }

    public function unitPriceAt(DateTimeImmutable $quotedAt): Money
    {
        if ($this->sale === null || $this->saleStartsAt === null || $this->saleEndsAt === null) {
            return $this->regular;
        }
        if ($quotedAt < $this->saleStartsAt || $quotedAt >= $this->saleEndsAt) {
            return $this->regular;
        }

        return $this->sale;
    }

    public static function isUtc(DateTimeImmutable $time): bool
    {
        return in_array($time->getTimezone()->getName(), ['UTC', 'Z', '+00:00', 'GMT'], true);
    }
}
