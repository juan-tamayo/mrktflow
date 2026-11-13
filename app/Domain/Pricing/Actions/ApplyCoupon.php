<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Pricing\Data\AppliedCoupon;
use App\Domain\Pricing\Data\CouponBasketLine;
use App\Domain\Pricing\Data\MerchandiseDiscount;
use App\Domain\Pricing\Data\Money;
use App\Domain\Pricing\Exceptions\PricingCouponExhausted;
use App\Domain\Pricing\Exceptions\PricingCouponRejected;
use App\Domain\Pricing\Rules\AllocateDiscount;
use App\Domain\Pricing\Rules\MatchPromotionLines;
use App\Domain\Pricing\Support\FirstCustomerEligibility;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ApplyCoupon
{
    public function __construct(
        private readonly MatchPromotionLines $matcher = new MatchPromotionLines,
        private readonly FirstCustomerEligibility $firstCustomers = new FirstCustomerEligibility,
        private readonly AllocateDiscount $allocator = new AllocateDiscount,
    ) {}

    /**
     * @param  list<CouponBasketLine>  $lines
     */
    public function apply(string $code, array $lines, DateTimeImmutable $quotedAt, ?string $customerKey = null): AppliedCoupon
    {
        $normalized = strtoupper(trim($code));
        if ($lines === [] || ! preg_match('/^[A-Z0-9][A-Z0-9_-]{2,31}$/', $normalized)
            || ($customerKey !== null && (! Str::isUuid($customerKey) || $customerKey !== strtolower($customerKey)))) {
            throw new PricingCouponRejected;
        }
        $coupon = DB::table('coupons')->where('code_normalized', $normalized)->first();
        if ($coupon === null || $this->string($coupon, 'status') !== 'active') {
            throw new PricingCouponRejected;
        }
        $this->assertGrant($coupon, $quotedAt, $customerKey);
        $starts = $this->instant($coupon, 'starts_at');
        $ends = $this->instant($coupon, 'ends_at');
        if (($starts !== null && $quotedAt < $starts) || ($ends !== null && $quotedAt >= $ends)) {
            throw new PricingCouponRejected;
        }
        $max = $this->nullableInt($coupon, 'max_redemptions');
        if ($max !== null && $this->int($coupon, 'held_count') + $this->int($coupon, 'redeemed_count') >= $max) {
            throw new PricingCouponExhausted;
        }
        $perCustomer = $this->nullableInt($coupon, 'max_redemptions_per_customer');
        if ($perCustomer !== null && $customerKey !== null) {
            $used = DB::table('coupon_holds')
                ->where('coupon_id', $this->string($coupon, 'id'))
                ->where('customer_key', $customerKey)
                ->whereIn('status', ['active', 'committed'])
                ->count();
            if ($used >= $perCustomer) {
                throw new PricingCouponExhausted;
            }
        }
        $scopes = DB::table('coupon_scopes')->where('coupon_id', $this->string($coupon, 'id'))->get();
        $productScopes = [];
        $categoryScopes = [];
        foreach ($scopes as $scope) {
            if ($this->string($scope, 'scope_type') === 'product') {
                $productScopes[] = $this->string($scope, 'target_id');
            } else {
                $categoryScopes[] = $this->string($scope, 'target_id');
            }
        }
        $matched = $this->matcher->eligible($lines, $quotedAt, $productScopes, $categoryScopes, $this->flag($coupon, 'exclude_sale_priced'));
        if ($matched['lines'] === [] || $matched['total'] < $this->int($coupon, 'min_eligible_subtotal')) {
            throw new PricingCouponRejected;
        }
        $ids = array_map(fn (CouponBasketLine $line): string => $line->line->lineId, $matched['lines']);
        $mode = $this->string($coupon, 'mode');
        $currency = $this->string($coupon, 'currency');
        $exponent = $this->int($coupon, 'exponent');
        $cap = $this->grantMaxDiscount($coupon);
        if ($mode === 'percentage') {
            $raw = $this->allocator->percentageOf($matched['total'], $this->int($coupon, 'percent_numerator'), $this->int($coupon, 'percent_denominator'));
            $capped = $cap === null ? $raw : min($raw, $cap);
            if ($capped < 1) {
                throw new PricingCouponRejected;
            }
            $discount = $cap !== null
                ? MerchandiseDiscount::fixed(Money::of($capped, $currency, $exponent), $ids)
                : MerchandiseDiscount::percentage($this->int($coupon, 'percent_numerator'), $this->int($coupon, 'percent_denominator'), $ids);
        } elseif ($mode === 'fixed') {
            $amount = $this->int($coupon, 'amount');
            if ($amount > $matched['total']) {
                throw new PricingCouponRejected;
            }
            $discount = MerchandiseDiscount::fixed(Money::of($amount, $currency, $exponent), $ids);
        } elseif ($mode === 'free_shipping') {
            $discount = MerchandiseDiscount::none();
        } else {
            throw new PricingCouponRejected;
        }

        $fullPercent = $mode === 'percentage'
            && $this->int($coupon, 'percent_numerator') > 0
            && $this->int($coupon, 'percent_numerator') === $this->int($coupon, 'percent_denominator')
            && $cap === null;

        return new AppliedCoupon(
            $this->string($coupon, 'id'),
            $this->int($coupon, 'revision'),
            $normalized,
            $mode,
            $discount,
            $mode === 'free_shipping' || $fullPercent,
            $ids,
            $this->flag($coupon, 'allows_stacking'),
        );
    }

    private function assertGrant(object $coupon, DateTimeImmutable $quotedAt, ?string $customerKey): void
    {
        $grant = DB::table('coupon_grants')->where('coupon_id', $this->string($coupon, 'id'))->first();
        if ($grant === null) {
            return;
        }
        if ($this->string($grant, 'status') !== 'issued') {
            throw new PricingCouponRejected;
        }
        $campaign = DB::table('promotion_campaigns')->where('id', $this->string($grant, 'campaign_id'))->first();
        if ($campaign === null || $this->string($campaign, 'status') !== 'active') {
            throw new PricingCouponRejected;
        }
        $expires = $this->instant($grant, 'expires_at');
        if ($expires !== null && $quotedAt >= $expires) {
            throw new PricingCouponRejected;
        }
        $recipient = $this->string($grant, 'recipient_customer_id');
        if ($recipient !== '' && $customerKey !== $recipient) {
            throw new PricingCouponRejected;
        }
        $versionId = $this->nullableString($campaign, 'current_version_id');
        $version = $versionId === null ? null : DB::table('promotion_versions')->where('id', $versionId)->first();
        if ($version !== null && $this->flag($version, 'first_customer_only') && ! $this->firstCustomers->eligible($customerKey, $this->string($campaign, 'id'))) {
            throw new PricingCouponRejected;
        }
        $budget = $version === null ? null : $this->nullableInt($version, 'discount_budget');
        if ($budget !== null && $this->int($campaign, 'held_amount') + $this->int($campaign, 'redeemed_amount') >= $budget) {
            throw new PricingCouponExhausted;
        }
    }

    private function grantMaxDiscount(object $coupon): ?int
    {
        $grant = DB::table('coupon_grants')->where('coupon_id', $this->string($coupon, 'id'))->first();
        if ($grant === null) {
            return null;
        }
        $campaign = DB::table('promotion_campaigns')->where('id', $this->string($grant, 'campaign_id'))->first();
        $versionId = $campaign === null ? null : $this->nullableString($campaign, 'current_version_id');
        $version = $versionId === null ? null : DB::table('promotion_versions')->where('id', $versionId)->first();

        return $version === null ? null : $this->nullableInt($version, 'max_discount_amount');
    }

    private function nullableString(object $row, string $key): ?string
    {
        $value = $this->string($row, $key);

        return $value === '' ? null : $value;
    }

    private function instant(object $row, string $key): ?DateTimeImmutable
    {
        $value = $this->string($row, $key);
        if ($value === '') {
            return null;
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInt(object $row, string $key): ?int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function flag(object $row, string $key): bool
    {
        $value = get_object_vars($row)[$key] ?? null;

        return $value === true || $value === 1 || $value === '1';
    }
}
