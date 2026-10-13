<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

use App\Domain\Platform\Support\SetupChoices;
use App\Domain\Pricing\Exceptions\PricingInvalidMoney;
use NumberFormatter;

final readonly class Money
{
    public const MAX_SAFE = 9007199254740991;

    public int $amount;

    public string $currency;

    public int $exponent;

    private function __construct(int $amount, string $currency, int $exponent)
    {
        $this->amount = $amount;
        $this->currency = $currency;
        $this->exponent = $exponent;
    }

    public static function of(int $amount, string $currency, int $exponent): self
    {
        if ($amount < 0 || $amount > self::MAX_SAFE || $exponent < 0 || $exponent > 3) {
            throw new PricingInvalidMoney;
        }
        if (! in_array($currency, array_column((new SetupChoices)->currencies(), 'code'), true)) {
            throw new PricingInvalidMoney;
        }
        $formatter = NumberFormatter::create('en', NumberFormatter::CURRENCY);
        if (! $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency)) {
            throw new PricingInvalidMoney;
        }
        if ($formatter->getAttribute(NumberFormatter::FRACTION_DIGITS) !== $exponent) {
            throw new PricingInvalidMoney;
        }

        return new self($amount, $currency, $exponent);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);
        if ($this->amount > self::MAX_SAFE - $other->amount) {
            throw new PricingInvalidMoney;
        }

        return new self($this->amount + $other->amount, $this->currency, $this->exponent);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);
        if ($other->amount > $this->amount) {
            throw new PricingInvalidMoney;
        }

        return new self($this->amount - $other->amount, $this->currency, $this->exponent);
    }

    public function times(int $quantity): self
    {
        if ($quantity < 1 || $quantity > 1_000_000) {
            throw new PricingInvalidMoney;
        }
        if ($this->amount > intdiv(self::MAX_SAFE, $quantity)) {
            throw new PricingInvalidMoney;
        }

        return new self($this->amount * $quantity, $this->currency, $this->exponent);
    }

    public function withAmount(int $amount): self
    {
        return self::of($amount, $this->currency, $this->exponent);
    }

    public function formatted(): string
    {
        $scale = self::scale($this->exponent);
        $fraction = $this->exponent === 0 ? '' : '.'.str_pad((string) ($this->amount % $scale), $this->exponent, '0', STR_PAD_LEFT);

        return $this->currency.' '.intdiv($this->amount, $scale).$fraction;
    }

    /** @return array{amount: int, currency: string, exponent: int, formatted: string} */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'exponent' => $this->exponent,
            'formatted' => $this->formatted(),
        ];
    }

    public static function scale(int $exponent): int
    {
        return match ($exponent) {
            0 => 1,
            1 => 10,
            2 => 100,
            3 => 1000,
            default => throw new PricingInvalidMoney,
        };
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency || $this->exponent !== $other->exponent) {
            throw new PricingInvalidMoney;
        }
    }
}
