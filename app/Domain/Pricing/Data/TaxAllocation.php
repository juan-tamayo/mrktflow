<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

final readonly class TaxAllocation
{
    public function __construct(
        public string $lineId,
        public string $classKey,
        public string $ruleId,
        public int $rateNumerator,
        public int $rateDenominator,
        public Money $taxableBasis,
        public Money $tax,
        public TaxDestination $destination,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lineId' => $this->lineId,
            'classKey' => $this->classKey,
            'ruleId' => $this->ruleId,
            'rateNumerator' => $this->rateNumerator,
            'rateDenominator' => $this->rateDenominator,
            'taxableBasis' => $this->taxableBasis->amount,
            'tax' => $this->tax->amount,
            'destination' => [
                'country' => $this->destination->countryCode,
                'region' => $this->destination->regionCode,
                'postal' => $this->destination->postalCode,
            ],
        ];
    }
}
