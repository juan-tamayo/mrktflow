<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Data;

use App\Domain\Shipping\Exceptions\ShippingInvalidQuote;
use App\Domain\Shipping\Support\StoreMoneySettings;
use Illuminate\Support\Str;

final readonly class ShippingQuoteLine
{
    public function __construct(
        public string $lineId,
        public string $kind,
        public string $classKey,
        public int $quantity,
        public int $discountedAmount,
        public ?int $weightGrams,
        public ?int $lengthMm,
        public ?int $widthMm,
        public ?int $heightMm,
    ) {
        if (! Str::isUuid($this->lineId) || $this->lineId !== strtolower($this->lineId)
            || ! in_array($this->kind, ['physical', 'digital'], true)
            || preg_match('/^[a-z][a-z0-9_]{1,30}$/', $this->classKey) !== 1
            || $this->quantity < 1 || $this->quantity > 1_000_000
            || $this->discountedAmount < 0 || $this->discountedAmount > StoreMoneySettings::MAX_SAFE) {
            throw new ShippingInvalidQuote;
        }
        if ($this->kind === 'digital') {
            if ($this->weightGrams !== null || $this->lengthMm !== null || $this->widthMm !== null || $this->heightMm !== null) {
                throw new ShippingInvalidQuote;
            }

            return;
        }
        foreach ([$this->weightGrams, $this->lengthMm, $this->widthMm, $this->heightMm] as $measure) {
            if ($measure !== null && ($measure < 1 || $measure > 1_000_000)) {
                throw new ShippingInvalidQuote;
            }
        }
    }

    public function isPhysical(): bool
    {
        return $this->kind === 'physical';
    }
}
