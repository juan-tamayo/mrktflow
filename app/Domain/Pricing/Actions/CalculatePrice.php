<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Pricing\Contracts\PriceCalculator;
use App\Domain\Pricing\Data\LineAllocation;
use App\Domain\Pricing\Data\MerchandiseDiscount;
use App\Domain\Pricing\Data\Money;
use App\Domain\Pricing\Data\PricedLine;
use App\Domain\Pricing\Data\PriceQuote;
use App\Domain\Pricing\Data\PriceRequest;
use App\Domain\Pricing\Data\TaxAllocation;
use App\Domain\Pricing\Exceptions\PricingInvalidQuote;
use App\Domain\Pricing\Rules\AllocateDiscount;

final class CalculatePrice implements PriceCalculator
{
    public function __construct(private readonly AllocateDiscount $allocator = new AllocateDiscount) {}

    public function quote(PriceRequest $request): PriceQuote
    {
        $currency = $request->lines[0]->regular->currency;
        $exponent = $request->lines[0]->regular->exponent;
        /** @var array<string, int> $subtotals */
        $subtotals = [];
        /** @var list<array{line: PricedLine, unit: Money, subtotal: Money}> $priced */
        $priced = [];
        foreach ($request->lines as $line) {
            $unit = $line->unitPriceAt($request->quotedAt);
            if ($unit->currency !== $currency || $unit->exponent !== $exponent) {
                throw new PricingInvalidQuote;
            }
            $lineSubtotal = $unit->times($line->quantity);
            $priced[] = ['line' => $line, 'unit' => $unit, 'subtotal' => $lineSubtotal];
            $subtotals[$line->lineId] = $lineSubtotal->amount;
        }
        $merchandise = 0;
        foreach ($subtotals as $amount) {
            if ($merchandise > Money::MAX_SAFE - $amount) {
                throw new PricingInvalidQuote;
            }
            $merchandise += $amount;
        }
        $stack = $request->stackedDiscounts();
        $shares = $this->allocator->sequential($subtotals, $stack);
        $discountAmount = 0;
        foreach ($shares as $appliedShare) {
            if ($discountAmount > Money::MAX_SAFE - $appliedShare) {
                throw new PricingInvalidQuote;
            }
            $discountAmount += $appliedShare;
        }
        $allocations = [];
        $zero = Money::of(0, $currency, $exponent);
        foreach ($priced as $item) {
            $line = $item['line'];
            $applied = $shares[$line->lineId] ?? 0;
            $lineSubtotal = $item['subtotal'];
            $lineDiscount = $lineSubtotal->withAmount($applied);
            $allocations[] = new LineAllocation(
                $line->lineId,
                $line->variantId,
                $line->quantity,
                $item['unit'],
                $lineSubtotal,
                $lineDiscount,
                $lineSubtotal->minus($lineDiscount),
                $this->allocator->splitAcrossUnits($applied, $line->quantity),
            );
        }
        $merchandiseMoney = Money::of($merchandise, $currency, $exponent);
        $discountMoney = $zero->withAmount($discountAmount);
        $discounted = $merchandiseMoney->minus($discountMoney);
        $shipping = $request->shipping ?? $zero;
        $tax = $zero;
        $grand = $discounted->plus($shipping)->plus($tax);
        $quote = new PriceQuote(
            $allocations,
            $merchandiseMoney,
            $discountMoney,
            $discounted,
            $shipping,
            $tax,
            $grand,
            $request->quotedAt,
            '',
        );
        if ($request->tax !== null) {
            $quote = app(CalculateTax::class)->apply($quote, $request->tax);
        }

        return new PriceQuote(
            $quote->lines,
            $quote->merchandiseSubtotal,
            $quote->merchandiseDiscount,
            $quote->discountedMerchandise,
            $quote->shipping,
            $quote->tax,
            $quote->grandTotal,
            $quote->quotedAt,
            hash('sha256', $this->fingerprintPayload($request, $quote)),
            $quote->priceMode,
            $quote->taxLines,
        );
    }

    private function fingerprintPayload(PriceRequest $request, PriceQuote $quote): string
    {
        $payload = [
            'quotedAt' => $request->quotedAt->format('Y-m-d\\TH:i:s\\Z'),
            'discount' => array_map(fn (MerchandiseDiscount $discount): array => [
                'mode' => $discount->mode,
                'fixed' => $discount->fixed?->amount,
                'percentNumerator' => $discount->percentNumerator,
                'percentDenominator' => $discount->percentDenominator,
                'eligibleLineIds' => $discount->eligibleLineIds,
            ], $request->stackedDiscounts()),
            'shipping' => $request->shipping === null ? 0 : $request->shipping->amount,
            'priceMode' => $quote->priceMode,
            'taxLines' => array_map(fn (TaxAllocation $line): array => $line->toArray(), $quote->taxLines),
            'lines' => array_map(fn (LineAllocation $line): array => [
                'lineId' => $line->lineId,
                'variantId' => $line->variantId,
                'quantity' => $line->quantity,
                'unitPrice' => $line->unitPrice->amount,
                'lineSubtotal' => $line->lineSubtotal->amount,
                'discount' => $line->discount->amount,
                'lineTotal' => $line->lineTotal->amount,
                'unitDiscounts' => $line->unitDiscounts,
            ], $quote->lines),
            'totals' => [
                'merchandiseSubtotal' => $quote->merchandiseSubtotal->amount,
                'merchandiseDiscount' => $quote->merchandiseDiscount->amount,
                'discountedMerchandise' => $quote->discountedMerchandise->amount,
                'shipping' => $quote->shipping->amount,
                'tax' => $quote->tax->amount,
                'grandTotal' => $quote->grandTotal->amount,
                'currency' => $quote->grandTotal->currency,
                'exponent' => $quote->grandTotal->exponent,
            ],
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $json;
    }
}
