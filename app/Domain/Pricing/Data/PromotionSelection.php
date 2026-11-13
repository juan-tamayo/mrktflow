<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

final readonly class PromotionSelection
{
    /**
     * @param  list<PromotionOffer>  $offers
     * @param  list<MerchandiseDiscount>  $discounts
     */
    public function __construct(
        public array $offers,
        public array $discounts,
        public bool $waivesShipping,
        public ?string $enteredCode,
        public bool $enteredCodeApplied,
    ) {}
}
