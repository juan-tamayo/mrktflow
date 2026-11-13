<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

final readonly class PromotionSelection
{
    /**
     * @param  list<PromotionOffer>  $offers
     * @param  list<MerchandiseDiscount>  $discounts
     * @param  list<array{campaign_id: string, revision: int, coupon_id: ?string, amount: int, first_customer: bool}>  $holds
     */
    public function __construct(
        public array $offers,
        public array $discounts,
        public bool $waivesShipping,
        public ?string $enteredCode,
        public bool $enteredCodeApplied,
        public array $holds = [],
    ) {}
}
