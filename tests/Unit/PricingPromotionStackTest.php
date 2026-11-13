<?php

declare(strict_types=1);

use App\Domain\Pricing\Actions\CalculatePrice;
use App\Domain\Pricing\Actions\HoldCoupon;
use App\Domain\Pricing\Actions\SavePromotionCampaign;
use App\Domain\Pricing\Actions\SelectPromotions;
use App\Domain\Pricing\Data\CouponBasketLine;
use App\Domain\Pricing\Data\CouponHold;
use App\Domain\Pricing\Data\MerchandiseDiscount;
use App\Domain\Pricing\Data\Money;
use App\Domain\Pricing\Data\PricedLine;
use App\Domain\Pricing\Data\PriceRequest;
use App\Domain\Pricing\Data\PromotionOffer;
use App\Domain\Pricing\Data\TaxableLine;
use App\Domain\Pricing\Data\TaxContext;
use App\Domain\Pricing\Rules\AllocateDiscount;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

function stackUsd(int $amount): Money
{
    return Money::of($amount, 'USD', 2);
}

function stackAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-13T00:00:00Z');
}

function stackLine(string $suffix, int $quantity, Money $regular, ?Money $sale = null): CouponBasketLine
{
    $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa'.$suffix;
    $variant = str_replace('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb', $id);
    $starts = $sale === null ? null : new DateTimeImmutable('2026-01-01T00:00:00Z');
    $ends = $sale === null ? null : new DateTimeImmutable('2026-12-31T00:00:00Z');

    return new CouponBasketLine(
        new PricedLine($id, $variant, $quantity, $regular, $sale, $starts, $ends),
        'cccccccc-cccc-4ccc-8ccc-ccccccccccc'.$suffix,
    );
}

function stackTax(array $lines, string $mode): TaxContext
{
    $taxable = [];
    foreach ($lines as $line) {
        $taxable[] = new TaxableLine($line->line->lineId, 'standard', 'physical');
    }

    return new TaxContext($taxable, $mode, null, pricingTaxDestination(), 'standard');
}

function stackQuote(array $lines, array $discounts, Money $shipping, TaxContext $tax): \App\Domain\Pricing\Data\PriceQuote
{
    $priced = array_map(fn (CouponBasketLine $line): PricedLine => $line->line, $lines);

    return (new CalculatePrice)->quote(new PriceRequest($priced, stackAt(), null, $shipping, $tax, $discounts));
}

it('PricingPromotionStack allocates sequential stacked discounts against remaining eligible bases', function (): void {
    $shares = (new AllocateDiscount)->sequential(
        ['line-a' => 333, 'line-b' => 667],
        [
            MerchandiseDiscount::percentage(10, 100, ['line-a', 'line-b']),
            MerchandiseDiscount::percentage(10, 100, ['line-a', 'line-b']),
        ],
    );
    expect($shares['line-a'] + $shares['line-b'])->toBe(190)
        ->and($shares['line-a'])->toBe(63)
        ->and($shares['line-b'])->toBe(127);
});

it('PricingPromotionStack prefers the better automatic total and leaves the entered code visible unused', function (): void {
    $owner = catalogInstalledOwner();
    pricingSavedTaxRules($owner);
    pricingSavedCoupon($owner, [
        'code' => 'SAVE10',
        'percent_numerator' => 10,
        'max_redemptions' => 25,
        'max_redemptions_per_customer' => 5,
    ]);
    app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'automatic',
        'name' => 'Sitewide twenty',
        'percent_numerator' => 20,
        'allows_stacking' => false,
    ]));
    $lines = [stackLine('1', 1, stackUsd(1099))];
    $shipping = stackUsd(300);
    $tax = stackTax($lines, 'exclusive');
    $selection = app(SelectPromotions::class)->select($lines, stackAt(), 'save10', null, $shipping, $tax);
    expect($selection->enteredCode)->toBe('SAVE10')
        ->and($selection->enteredCodeApplied)->toBeFalse()
        ->and($selection->waivesShipping)->toBeFalse();
    $applied = array_values(array_filter($selection->offers, fn (PromotionOffer $offer): bool => $offer->status === 'applied'));
    $rejected = array_values(array_filter($selection->offers, fn (PromotionOffer $offer): bool => $offer->status === 'rejected'));
    expect($applied)->toHaveCount(1)
        ->and($applied[0]->kind)->toBe('automatic')
        ->and($applied[0]->reasonCode)->toBe('applied_best_total')
        ->and($rejected)->toHaveCount(1)
        ->and($rejected[0]->kind)->toBe('entered_code')
        ->and($rejected[0]->code)->toBe('SAVE10')
        ->and($rejected[0]->reasonCode)->toBe('rejected_worse_total')
        ->and($rejected[0]->reason)->toContain('not applied');
    $quote = stackQuote($lines, $selection->discounts, $shipping, $tax);
    expect($quote->merchandiseDiscount->amount)->toBe(220)
        ->and($quote->discountedMerchandise->amount)->toBe(879)
        ->and($quote->tax->amount)->toBe(118)
        ->and($quote->grandTotal->amount)->toBe(1297);
    expect(DB::table('coupon_holds')->count())->toBe(0);
    $this->get('/')->assertStatus(503);
});

it('PricingPromotionStack uses the entered coupon on an equal total and rejects the automatic offer', function (): void {
    $owner = catalogInstalledOwner();
    pricingSavedTaxRules($owner);
    pricingSavedCoupon($owner, [
        'code' => 'SAVE10',
        'percent_numerator' => 10,
        'max_redemptions' => 25,
        'max_redemptions_per_customer' => 5,
    ]);
    app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'automatic',
        'name' => 'Matching ten',
        'percent_numerator' => 10,
    ]));
    $lines = [stackLine('1', 1, stackUsd(1099))];
    $shipping = stackUsd(300);
    $tax = stackTax($lines, 'exclusive');
    $selection = app(SelectPromotions::class)->select($lines, stackAt(), 'SAVE10', null, $shipping, $tax);
    expect($selection->enteredCodeApplied)->toBeTrue();
    $applied = array_values(array_filter($selection->offers, fn (PromotionOffer $offer): bool => $offer->status === 'applied'));
    $rejected = array_values(array_filter($selection->offers, fn (PromotionOffer $offer): bool => $offer->status === 'rejected'));
    expect($applied)->toHaveCount(1)
        ->and($applied[0]->kind)->toBe('entered_code')
        ->and($applied[0]->reasonCode)->toBe('applied_entered_tie')
        ->and($rejected)->toHaveCount(1)
        ->and($rejected[0]->kind)->toBe('automatic')
        ->and($rejected[0]->reasonCode)->toBe('rejected_entered_tie');
    $quote = stackQuote($lines, $selection->discounts, $shipping, $tax);
    expect($quote->merchandiseDiscount->amount)->toBe(110)
        ->and($quote->grandTotal->amount)->toBe(1418);
});

it('PricingPromotionStack stacks one automatic merchandise reduction then the entered coupon with odd-cent tax', function (): void {
    $owner = catalogInstalledOwner();
    pricingSavedTaxRules($owner);
    pricingSavedCoupon($owner, [
        'code' => 'SAVE10',
        'percent_numerator' => 10,
        'allows_stacking' => true,
        'max_redemptions' => 25,
        'max_redemptions_per_customer' => 5,
    ]);
    app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'automatic',
        'name' => 'Stackable ten',
        'percent_numerator' => 10,
        'allows_stacking' => true,
    ]));
    $lines = [stackLine('1', 1, stackUsd(1099))];
    $shipping = stackUsd(300);
    $exclusive = stackTax($lines, 'exclusive');
    $selection = app(SelectPromotions::class)->select($lines, stackAt(), 'SAVE10', null, $shipping, $exclusive);
    expect($selection->enteredCodeApplied)->toBeTrue()
        ->and($selection->discounts)->toHaveCount(2);
    $applied = array_values(array_filter($selection->offers, fn (PromotionOffer $offer): bool => $offer->status === 'applied'));
    $appliedKinds = [];
    foreach ($applied as $offer) {
        $appliedKinds[$offer->kind] = $offer;
    }
    expect($applied)->toHaveCount(2)
        ->and($appliedKinds['automatic']->reasonCode)->toBe('applied_stacked')
        ->and($appliedKinds['entered_code']->reasonCode)->toBe('applied_stacked');
    $exclusiveQuote = stackQuote($lines, $selection->discounts, $shipping, $exclusive);
    expect($exclusiveQuote->merchandiseDiscount->amount)->toBe(209)
        ->and($exclusiveQuote->discountedMerchandise->amount)->toBe(890)
        ->and($exclusiveQuote->tax->amount)->toBe(119)
        ->and($exclusiveQuote->grandTotal->amount)->toBe(1309);
    $rules = app(\App\Domain\Pricing\Actions\ReadTaxRules::class)->staffRules();
    app(\App\Domain\Pricing\Actions\SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'price_mode' => 'inclusive',
        'version' => $rules['version'],
        'rules' => [pricingTaxRuleInput(['name' => 'US ten percent'])],
    ]));
    $inclusive = stackTax($lines, 'inclusive');
    $inclusiveSelection = app(SelectPromotions::class)->select($lines, stackAt(), 'SAVE10', null, $shipping, $inclusive);
    $inclusiveQuote = stackQuote($lines, $inclusiveSelection->discounts, $shipping, $inclusive);
    expect($inclusiveQuote->merchandiseDiscount->amount)->toBe(209)
        ->and($inclusiveQuote->tax->amount)->toBe(108)
        ->and($inclusiveQuote->grandTotal->amount)->toBe(1190);
});

it('PricingPromotionStack uses sale prices as the discount base unless the rule excludes them', function (): void {
    $owner = catalogInstalledOwner();
    pricingSavedCoupon($owner, [
        'code' => 'SAVE10',
        'percent_numerator' => 10,
        'max_redemptions' => 25,
        'max_redemptions_per_customer' => 5,
    ]);
    $saleLine = stackLine('1', 1, stackUsd(2000), stackUsd(1500));
    $regularLine = stackLine('2', 1, stackUsd(2000));
    $withSale = app(SelectPromotions::class)->select([$saleLine], stackAt(), 'SAVE10');
    expect($withSale->enteredCodeApplied)->toBeTrue();
    $saleQuote = (new CalculatePrice)->quote(new PriceRequest(
        [$saleLine->line],
        stackAt(),
        null,
        null,
        null,
        $withSale->discounts,
    ));
    expect($saleQuote->merchandiseSubtotal->amount)->toBe(1500)
        ->and($saleQuote->merchandiseDiscount->amount)->toBe(150);
    pricingSavedCoupon($owner, [
        'code' => 'NOSALE',
        'percent_numerator' => 10,
        'exclude_sale_priced' => true,
        'max_redemptions' => 25,
        'max_redemptions_per_customer' => 5,
    ]);
    expect(fn () => app(SelectPromotions::class)->select([$saleLine], stackAt(), 'NOSALE'))
        ->toThrow(\App\Domain\Pricing\Exceptions\PricingCouponRejected::class);
    $mixed = app(SelectPromotions::class)->select([$saleLine, $regularLine], stackAt(), 'NOSALE');
    $mixedQuote = (new CalculatePrice)->quote(new PriceRequest(
        [$saleLine->line, $regularLine->line],
        stackAt(),
        null,
        null,
        null,
        $mixed->discounts,
    ));
    expect($mixedQuote->merchandiseSubtotal->amount)->toBe(3500)
        ->and($mixedQuote->merchandiseDiscount->amount)->toBe(200);
});

it('PricingPromotionStack keeps at most one automatic offer and does not hold a losing entered code', function (): void {
    $owner = catalogInstalledOwner();
    pricingSavedCoupon($owner, [
        'code' => 'SAVE10',
        'percent_numerator' => 10,
        'max_redemptions' => 1,
        'max_redemptions_per_customer' => 1,
    ]);
    app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'automatic',
        'name' => 'Fifteen',
        'percent_numerator' => 15,
    ]));
    app(SavePromotionCampaign::class)->save($owner, pricingCampaignInput([
        'kind' => 'automatic',
        'name' => 'Twenty five',
        'percent_numerator' => 25,
    ]));
    $lines = [stackLine('1', 1, stackUsd(1000))];
    $selection = app(SelectPromotions::class)->select($lines, stackAt(), 'SAVE10');
    $automatics = array_values(array_filter($selection->offers, fn (PromotionOffer $offer): bool => $offer->kind === 'automatic'));
    $appliedAutomatic = array_values(array_filter($automatics, fn (PromotionOffer $offer): bool => $offer->status === 'applied'));
    $rejectedAutomatic = array_values(array_filter($automatics, fn (PromotionOffer $offer): bool => $offer->status === 'rejected'));
    expect($appliedAutomatic)->toHaveCount(1)
        ->and($appliedAutomatic[0]->reasonCode)->toBe('applied_best_total')
        ->and($rejectedAutomatic)->toHaveCount(1)
        ->and($rejectedAutomatic[0]->reasonCode)->toBe('rejected_not_selected')
        ->and($selection->enteredCodeApplied)->toBeFalse();
    $quote = (new CalculatePrice)->quote(new PriceRequest(
        [$lines[0]->line],
        stackAt(),
        null,
        null,
        null,
        $selection->discounts,
    ));
    expect($quote->merchandiseDiscount->amount)->toBe(250);
    $couponId = (string) DB::table('coupons')->where('code_normalized', 'SAVE10')->value('id');
    expect(fn () => app(HoldCoupon::class)->hold(new CouponHold(
        (string) Str::uuid(),
        (string) Str::uuid(),
        $couponId,
        1,
        null,
        stackAt()->modify('+15 minutes'),
    )))->not->toThrow(\App\Domain\Pricing\Exceptions\PricingCouponExhausted::class);
});
