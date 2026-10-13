<?php

declare(strict_types=1);

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Pricing\Actions\SaveCoupon;
use App\Domain\Pricing\Data\CouponBasketLine;
use App\Domain\Pricing\Data\Money;
use App\Domain\Pricing\Data\PricedLine;
use Illuminate\Support\Str;

/** @param array<string, mixed> $overrides */
function pricingCouponInput(array $overrides = []): array
{
    return array_replace([
        'code' => 'SAVE10',
        'name' => 'Studio welcome',
        'mode' => 'percentage',
        'percent_numerator' => 10,
        'percent_denominator' => 100,
        'amount' => null,
        'min_eligible_subtotal' => 0,
        'starts_at' => '2026-01-01T00:00:00Z',
        'ends_at' => '2026-12-31T00:00:00Z',
        'max_redemptions' => 1,
        'max_redemptions_per_customer' => 1,
        'status' => 'active',
        'product_ids' => [],
        'category_ids' => [],
    ], $overrides);
}

function pricingCouponUsd(int $amount): Money
{
    return Money::of($amount, 'USD', 2);
}

/**
 * @param  list<string>  $categoryIds
 */
function pricingCouponLine(string $productId, string $variantId, int $quantity, Money $regular, array $categoryIds = []): CouponBasketLine
{
    return new CouponBasketLine(
        new PricedLine((string) Str::uuid(), $variantId, $quantity, $regular),
        $productId,
        $categoryIds,
    );
}

/** @param array<string, mixed> $overrides */
function pricingSavedCoupon(StaffAccount $owner, array $overrides = []): array
{
    return app(SaveCoupon::class)->save($owner, pricingCouponInput($overrides));
}
