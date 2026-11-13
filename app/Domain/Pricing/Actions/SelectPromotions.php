<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Pricing\Data\AppliedCoupon;
use App\Domain\Pricing\Data\CouponBasketLine;
use App\Domain\Pricing\Data\MerchandiseDiscount;
use App\Domain\Pricing\Data\Money;
use App\Domain\Pricing\Data\PricedLine;
use App\Domain\Pricing\Data\PriceRequest;
use App\Domain\Pricing\Data\PromotionOffer;
use App\Domain\Pricing\Data\PromotionSelection;
use App\Domain\Pricing\Data\TaxContext;
use App\Domain\Pricing\Rules\AllocateDiscount;
use App\Domain\Pricing\Rules\MatchPromotionLines;
use App\Domain\Pricing\Support\FirstCustomerEligibility;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class SelectPromotions
{
    public function __construct(
        private readonly ApplyCoupon $coupons = new ApplyCoupon,
        private readonly CalculatePrice $prices = new CalculatePrice,
        private readonly MatchPromotionLines $matcher = new MatchPromotionLines,
        private readonly FirstCustomerEligibility $firstCustomers = new FirstCustomerEligibility,
        private readonly AllocateDiscount $allocator = new AllocateDiscount,
    ) {}

    /**
     * @param  list<CouponBasketLine>  $lines
     */
    public function select(
        array $lines,
        DateTimeImmutable $quotedAt,
        ?string $enteredCode = null,
        ?string $customerKey = null,
        ?Money $shipping = null,
        ?TaxContext $tax = null,
    ): PromotionSelection {
        $entered = null;
        $normalized = $enteredCode === null || trim($enteredCode) === '' ? null : strtoupper(trim($enteredCode));
        if ($normalized !== null) {
            $entered = $this->coupons->apply($normalized, $lines, $quotedAt, $customerKey);
        }
        $automatics = $this->automatics($lines, $quotedAt, $customerKey);
        /** @var list<array{auto: ?array<string, mixed>, coupon: ?AppliedCoupon, stack: bool}> $candidates */
        $candidates = [];
        if ($entered !== null) {
            $candidates[] = ['auto' => null, 'coupon' => $entered, 'stack' => false];
        }
        foreach ($automatics as $automatic) {
            $candidates[] = ['auto' => $automatic, 'coupon' => null, 'stack' => false];
            if ($entered !== null && $entered->allowsStacking && (bool) $automatic['allows_stacking']) {
                $candidates[] = ['auto' => $automatic, 'coupon' => $entered, 'stack' => true];
            }
        }
        if ($candidates === []) {
            return new PromotionSelection([], [], false, $entered?->code, false);
        }
        $priced = array_map(fn (CouponBasketLine $line): PricedLine => $line->line, $lines);
        $bestIndex = 0;
        $bestTotal = null;
        $bestHasCoupon = false;
        $bestAutoId = '';
        foreach ($candidates as $index => $candidate) {
            $built = $this->build($candidate['auto'], $candidate['coupon']);
            $charge = $built['waives'] ? ($shipping?->withAmount(0) ?? null) : $shipping;
            $quote = $this->prices->quote(new PriceRequest($priced, $quotedAt, null, $charge, $tax, $built['discounts']));
            $total = $quote->grandTotal->amount;
            $hasCoupon = $candidate['coupon'] !== null;
            $autoId = is_array($candidate['auto']) ? (string) $candidate['auto']['id'] : '';
            $better = $bestTotal === null
                || $total < $bestTotal
                || ($total === $bestTotal && $hasCoupon && ! $bestHasCoupon)
                || ($total === $bestTotal && $hasCoupon === $bestHasCoupon && strcmp($autoId, $bestAutoId) < 0);
            if ($better) {
                $bestIndex = $index;
                $bestTotal = $total;
                $bestHasCoupon = $hasCoupon;
                $bestAutoId = $autoId;
            }
        }
        $winner = $candidates[$bestIndex] ?? ['auto' => null, 'coupon' => null, 'stack' => false];
        $built = $this->build($winner['auto'], $winner['coupon']);
        $winnerTotal = $bestTotal;
        $offers = [];
        if ($entered !== null) {
            if ($winner['coupon'] !== null) {
                $offers[] = new PromotionOffer(
                    $entered->couponId,
                    'entered_code',
                    'applied',
                    $winner['stack'] ? 'applied_stacked' : ($this->tiedAutomatic($automatics, $winnerTotal, $priced, $quotedAt, $shipping, $tax) ? 'applied_entered_tie' : 'applied_best_total'),
                    $winner['stack']
                        ? 'Applied together because both offers allow stacking.'
                        : ($this->tiedAutomatic($automatics, $winnerTotal, $priced, $quotedAt, $shipping, $tax)
                            ? 'Applied because the entered code matches the best payable total.'
                            : 'Applied because it produced the better payable total.'),
                    $entered->code,
                    $entered->code,
                );
            } else {
                $offers[] = new PromotionOffer(
                    $entered->couponId,
                    'entered_code',
                    'rejected',
                    'rejected_worse_total',
                    'Not applied: another offer produced a better payable total.',
                    $entered->code,
                    $entered->code,
                );
            }
        }
        $winnerAutoId = is_array($winner['auto']) ? (string) $winner['auto']['id'] : null;
        foreach ($automatics as $automatic) {
            $id = (string) $automatic['id'];
            if ($winnerAutoId === $id) {
                $offers[] = new PromotionOffer(
                    $id,
                    'automatic',
                    'applied',
                    $winner['stack'] ? 'applied_stacked' : 'applied_best_total',
                    $winner['stack']
                        ? 'Applied together because both offers allow stacking.'
                        : 'Applied because it produced the better payable total.',
                    null,
                    (string) $automatic['name'],
                );

                continue;
            }
            $alone = $this->candidateTotal(['auto' => $automatic, 'coupon' => null, 'stack' => false], $priced, $quotedAt, $shipping, $tax);
            $reasonCode = 'rejected_not_selected';
            $reason = 'Not applied: another automatic offer produced a better payable total.';
            if ($winner['coupon'] !== null && $winner['auto'] === null && $alone === $winnerTotal) {
                $reasonCode = 'rejected_entered_tie';
                $reason = 'Not applied: the entered coupon produced an equal payable total.';
            } elseif ($winnerAutoId !== null) {
                $reasonCode = 'rejected_not_selected';
                $reason = 'Not applied: at most one automatic offer can apply.';
            } elseif ($entered !== null) {
                $reasonCode = 'rejected_worse_total';
                $reason = 'Not applied: the entered coupon produced a better payable total.';
            }
            $offers[] = new PromotionOffer(
                $id,
                'automatic',
                'rejected',
                $reasonCode,
                $reason,
                null,
                (string) $automatic['name'],
            );
        }

        return new PromotionSelection(
            $offers,
            $built['discounts'],
            $built['waives'],
            $entered?->code,
            $winner['coupon'] !== null,
            $this->holds($winner['auto'], $winner['coupon'] instanceof AppliedCoupon ? $winner['coupon'] : null, $priced, $quotedAt, $shipping, $tax),
        );
    }

    /**
     * @param  list<CouponBasketLine>  $lines
     * @return list<array<string, mixed>>
     */
    private function automatics(array $lines, DateTimeImmutable $quotedAt, ?string $customerKey): array
    {
        $rows = [];
        $query = DB::table('promotion_campaigns')
            ->join('promotion_versions', 'promotion_campaigns.current_version_id', '=', 'promotion_versions.id')
            ->where('promotion_campaigns.kind', 'automatic')
            ->where('promotion_campaigns.status', 'active')
            ->orderBy('promotion_campaigns.id')
            ->select([
                'promotion_campaigns.id',
                'promotion_campaigns.name',
                'promotion_campaigns.revision',
                'promotion_campaigns.held_amount',
                'promotion_campaigns.redeemed_amount',
                'promotion_versions.mode',
                'promotion_versions.amount',
                'promotion_versions.percent_numerator',
                'promotion_versions.percent_denominator',
                'promotion_versions.min_eligible_subtotal',
                'promotion_versions.currency',
                'promotion_versions.exponent',
                'promotion_versions.starts_at',
                'promotion_versions.ends_at',
                'promotion_versions.product_ids',
                'promotion_versions.category_ids',
                'promotion_versions.allows_stacking',
                'promotion_versions.exclude_sale_priced',
                'promotion_versions.discount_budget',
                'promotion_versions.max_discount_amount',
                'promotion_versions.first_customer_only',
            ]);
        foreach ($query->get() as $row) {
            $starts = $this->instant($row, 'starts_at');
            $ends = $this->instant($row, 'ends_at');
            if (($starts !== null && $quotedAt < $starts) || ($ends !== null && $quotedAt >= $ends)) {
                continue;
            }
            $products = $this->ids($row, 'product_ids');
            $categories = $this->ids($row, 'category_ids');
            if ($this->flag($row, 'first_customer_only') && ! $this->firstCustomers->eligible($customerKey, $this->string($row, 'id'))) {
                continue;
            }
            $matched = $this->matcher->eligible($lines, $quotedAt, $products, $categories, $this->flag($row, 'exclude_sale_priced'));
            if ($matched['lines'] === [] || $matched['total'] < $this->int($row, 'min_eligible_subtotal')) {
                continue;
            }
            $ids = array_map(fn (CouponBasketLine $line): string => $line->line->lineId, $matched['lines']);
            $mode = $this->string($row, 'mode');
            $currency = $this->string($row, 'currency');
            $exponent = $this->int($row, 'exponent');
            if ($mode === 'percentage') {
                $raw = $this->allocator->percentageOf(
                    $matched['total'],
                    $this->int($row, 'percent_numerator'),
                    $this->int($row, 'percent_denominator'),
                );
                $cap = $this->nullableInt($row, 'max_discount_amount');
                $capped = $cap === null ? $raw : min($raw, $cap);
                if ($capped < 1) {
                    continue;
                }
                $discount = $cap !== null
                    ? MerchandiseDiscount::fixed(Money::of($capped, $currency, $exponent), $ids)
                    : MerchandiseDiscount::percentage($this->int($row, 'percent_numerator'), $this->int($row, 'percent_denominator'), $ids);
            } elseif ($mode === 'fixed') {
                $amount = $this->int($row, 'amount');
                if ($amount > $matched['total']) {
                    continue;
                }
                $discount = MerchandiseDiscount::fixed(Money::of($amount, $currency, $exponent), $ids);
            } elseif ($mode === 'free_shipping') {
                $discount = MerchandiseDiscount::none();
            } else {
                continue;
            }
            $estimated = $mode === 'fixed' ? $this->int($row, 'amount') : ($mode === 'percentage'
                ? ($discount instanceof MerchandiseDiscount && $discount->mode === 'fixed' && $discount->fixed !== null
                    ? $discount->fixed->amount
                    : $this->allocator->percentageOf($matched['total'], $this->int($row, 'percent_numerator'), $this->int($row, 'percent_denominator')))
                : 0);
            $budget = $this->nullableInt($row, 'discount_budget');
            if ($budget !== null && $this->int($row, 'held_amount') + $this->int($row, 'redeemed_amount') + $estimated > $budget) {
                continue;
            }
            $fullPercent = $mode === 'percentage'
                && $this->int($row, 'percent_numerator') > 0
                && $this->int($row, 'percent_numerator') === $this->int($row, 'percent_denominator')
                && $this->nullableInt($row, 'max_discount_amount') === null;
            $rows[] = [
                'id' => $this->string($row, 'id'),
                'name' => $this->string($row, 'name'),
                'revision' => $this->int($row, 'revision'),
                'mode' => $mode,
                'discount' => $discount,
                'waives' => $mode === 'free_shipping' || $fullPercent,
                'allows_stacking' => $this->flag($row, 'allows_stacking'),
                'first_customer' => $this->flag($row, 'first_customer_only'),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>|null  $automatic
     * @return array{discounts: list<MerchandiseDiscount>, waives: bool}
     */
    private function build(?array $automatic, ?AppliedCoupon $coupon): array
    {
        $discounts = [];
        $waives = false;
        if ($automatic !== null) {
            $discount = $automatic['discount'];
            if ($discount instanceof MerchandiseDiscount && $discount->mode !== 'none') {
                $discounts[] = $discount;
            }
            $waives = (bool) $automatic['waives'];
        }
        if ($coupon !== null && $coupon->discount->mode !== 'none') {
            $discounts[] = $coupon->discount;
        }
        if ($coupon !== null && $coupon->waivesShipping) {
            $waives = true;
        }

        return ['discounts' => $discounts, 'waives' => $waives];
    }

    /**
     * @param  list<array<string, mixed>>  $automatics
     * @param  list<PricedLine>  $priced
     */
    private function tiedAutomatic(array $automatics, int $winnerTotal, array $priced, DateTimeImmutable $quotedAt, ?Money $shipping, ?TaxContext $tax): bool
    {
        foreach ($automatics as $automatic) {
            if ($this->candidateTotal(['auto' => $automatic, 'coupon' => null, 'stack' => false], $priced, $quotedAt, $shipping, $tax) === $winnerTotal) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $automatic
     * @param  list<PricedLine>  $priced
     * @return list<array{campaign_id: string, revision: int, coupon_id: ?string, amount: int, first_customer: bool}>
     */
    private function holds(?array $automatic, ?AppliedCoupon $coupon, array $priced, DateTimeImmutable $quotedAt, ?Money $shipping, ?TaxContext $tax): array
    {
        $holds = [];
        if (is_array($automatic)) {
            $autoAmount = $this->merchandiseDiscount(['auto' => $automatic, 'coupon' => null, 'stack' => false], $priced, $quotedAt, $shipping, $tax);
            $holds[] = [
                'campaign_id' => (string) $automatic['id'],
                'revision' => (int) $automatic['revision'],
                'coupon_id' => null,
                'amount' => $autoAmount,
                'first_customer' => (bool) $automatic['first_customer'],
            ];
        }
        if ($coupon !== null) {
            $grant = DB::table('coupon_grants')->where('coupon_id', $coupon->couponId)->first();
            if ($grant !== null) {
                $campaignId = $this->string($grant, 'campaign_id');
                $campaign = DB::table('promotion_campaigns')->where('id', $campaignId)->first();
                $version = $campaign === null ? null : DB::table('promotion_versions')->where('id', $this->string($campaign, 'current_version_id'))->first();
                $stacked = $automatic !== null;
                $total = $this->merchandiseDiscount(['auto' => $automatic, 'coupon' => $coupon, 'stack' => $stacked], $priced, $quotedAt, $shipping, $tax);
                $autoAmount = $automatic === null ? 0 : $this->merchandiseDiscount(['auto' => $automatic, 'coupon' => null, 'stack' => false], $priced, $quotedAt, $shipping, $tax);
                $holds[] = [
                    'campaign_id' => $campaignId,
                    'revision' => $campaign === null ? $coupon->revision : $this->int($campaign, 'revision'),
                    'coupon_id' => $coupon->couponId,
                    'amount' => max(0, $total - $autoAmount),
                    'first_customer' => $version !== null && $this->flag($version, 'first_customer_only'),
                ];
            }
        }

        return $holds;
    }

    /**
     * @param  array{auto: ?array<string, mixed>, coupon: ?AppliedCoupon, stack: bool}  $candidate
     * @param  list<PricedLine>  $priced
     */
    private function merchandiseDiscount(array $candidate, array $priced, DateTimeImmutable $quotedAt, ?Money $shipping, ?TaxContext $tax): int
    {
        $built = $this->build($candidate['auto'], $candidate['coupon']);
        $charge = $built['waives'] ? ($shipping?->withAmount(0) ?? null) : $shipping;

        return $this->prices->quote(new PriceRequest($priced, $quotedAt, null, $charge, $tax, $built['discounts']))->merchandiseDiscount->amount;
    }

    /**
     * @param  array{auto: ?array<string, mixed>, coupon: ?AppliedCoupon, stack: bool}  $candidate
     * @param  list<PricedLine>  $priced
     */
    private function candidateTotal(array $candidate, array $priced, DateTimeImmutable $quotedAt, ?Money $shipping, ?TaxContext $tax): int
    {
        $built = $this->build($candidate['auto'], $candidate['coupon']);
        $charge = $built['waives'] ? ($shipping?->withAmount(0) ?? null) : $shipping;

        return $this->prices->quote(new PriceRequest($priced, $quotedAt, null, $charge, $tax, $built['discounts']))->grandTotal->amount;
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

    /** @return list<string> */
    private function ids(object $row, string $key): array
    {
        $value = get_object_vars($row)[$key] ?? null;
        if (is_array($value)) {
            $ids = [];
            foreach ($value as $id) {
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }

            return array_values(array_unique($ids));
        }
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return [];
        }
        $ids = [];
        foreach ($decoded as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
