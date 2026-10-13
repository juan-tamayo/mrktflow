<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Data;

final readonly class ShippingQuote
{
    /**
     * @param  list<array{
     *     id: string,
     *     label: string,
     *     type: string,
     *     amount: int|null,
     *     offered: bool,
     *     reason: string|null,
     *     requiresDeliveryAddress: bool,
     *     promisesStock: bool,
     *     rateRuleId: string|null,
     *     pickup: array{
     *         name: string,
     *         street: string,
     *         city: string,
     *         region_code: string|null,
     *         postal_code: string,
     *         country_code: string,
     *         instructions: string|null
     *     }|null
     * }>  $eligible
     * @param  array{
     *     id: string,
     *     label: string,
     *     type: string,
     *     amount: int|null,
     *     offered: bool,
     *     reason: string|null,
     *     requiresDeliveryAddress: bool,
     *     promisesStock: bool,
     *     rateRuleId: string|null,
     *     pickup: array{
     *         name: string,
     *         street: string,
     *         city: string,
     *         region_code: string|null,
     *         postal_code: string,
     *         country_code: string,
     *         instructions: string|null
     *     }|null
     * }|null  $chosen
     */
    public function __construct(
        public bool $digitalOnly,
        public bool $requiresDeliveryAddress,
        public int $physicalSubtotal,
        public int $rulesRevision,
        public ?int $charge,
        public array $eligible,
        public ?array $chosen,
        public ?ShippingDestination $taxDestination,
        public ?string $pickupInstructions,
        public string $fingerprint,
        public string $currency,
        public int $exponent,
    ) {}
}
