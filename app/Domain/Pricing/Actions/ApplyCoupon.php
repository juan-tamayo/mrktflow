<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Pricing\Data\AppliedCoupon;
use App\Domain\Pricing\Data\CouponBasketLine;
use App\Domain\Pricing\Data\MerchandiseDiscount;
use App\Domain\Pricing\Data\Money;
use App\Domain\Pricing\Exceptions\PricingCouponExhausted;
use App\Domain\Pricing\Exceptions\PricingCouponRejected;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ApplyCoupon
{
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
        $categoryPaths = $this->categoryPaths($lines, $categoryScopes);
        $eligible = [];
        $eligibleTotal = 0;
        foreach ($lines as $line) {
            if (! $this->lineEligible($line, $productScopes, $categoryScopes, $categoryPaths)) {
                continue;
            }
            $subtotal = $line->line->unitPriceAt($quotedAt)->times($line->line->quantity);
            if ($eligibleTotal > Money::MAX_SAFE - $subtotal->amount) {
                throw new PricingCouponRejected;
            }
            $eligible[] = $line;
            $eligibleTotal += $subtotal->amount;
        }
        if ($eligible === [] || $eligibleTotal < $this->int($coupon, 'min_eligible_subtotal')) {
            throw new PricingCouponRejected;
        }
        $ids = array_map(fn (CouponBasketLine $line): string => $line->line->lineId, $eligible);
        $mode = $this->string($coupon, 'mode');
        $currency = $this->string($coupon, 'currency');
        $exponent = $this->int($coupon, 'exponent');
        if ($mode === 'percentage') {
            $discount = MerchandiseDiscount::percentage($this->int($coupon, 'percent_numerator'), $this->int($coupon, 'percent_denominator'), $ids);
        } elseif ($mode === 'fixed') {
            $amount = $this->int($coupon, 'amount');
            if ($amount > $eligibleTotal) {
                throw new PricingCouponRejected;
            }
            $discount = MerchandiseDiscount::fixed(Money::of($amount, $currency, $exponent), $ids);
        } elseif ($mode === 'free_shipping') {
            $discount = MerchandiseDiscount::none();
        } else {
            throw new PricingCouponRejected;
        }

        return new AppliedCoupon(
            $this->string($coupon, 'id'),
            $this->int($coupon, 'revision'),
            $normalized,
            $mode,
            $discount,
            $mode === 'free_shipping',
            $ids,
        );
    }

    /**
     * @param  list<CouponBasketLine>  $lines
     * @param  list<string>  $categoryScopes
     * @return array<string, string>
     */
    private function categoryPaths(array $lines, array $categoryScopes): array
    {
        $ids = $categoryScopes;
        foreach ($lines as $line) {
            foreach ($line->categoryIds as $categoryId) {
                $ids[] = $categoryId;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $paths = [];
        foreach (DB::table('categories')->whereIn('id', $ids)->get() as $row) {
            $paths[$this->string($row, 'id')] = $this->string($row, 'path');
        }

        return $paths;
    }

    /**
     * @param  list<string>  $productScopes
     * @param  list<string>  $categoryScopes
     * @param  array<string, string>  $categoryPaths
     */
    private function lineEligible(CouponBasketLine $line, array $productScopes, array $categoryScopes, array $categoryPaths): bool
    {
        if ($productScopes === [] && $categoryScopes === []) {
            return true;
        }
        if (in_array($line->productId, $productScopes, true)) {
            return true;
        }
        foreach ($categoryScopes as $scopeId) {
            $scopePath = $categoryPaths[$scopeId] ?? null;
            if ($scopePath === null) {
                continue;
            }
            foreach ($line->categoryIds as $categoryId) {
                $path = $categoryPaths[$categoryId] ?? null;
                if ($path === $scopePath || ($path !== null && str_starts_with($path, $scopePath.'/'))) {
                    return true;
                }
            }
        }

        return false;
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
}
