<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Pricing\Data\Money;
use App\Domain\Pricing\Data\PriceQuote;
use App\Domain\Pricing\Data\TaxableLine;
use App\Domain\Pricing\Data\TaxAllocation;
use App\Domain\Pricing\Data\TaxContext;
use App\Domain\Pricing\Data\TaxDestination;
use App\Domain\Pricing\Exceptions\PricingInvalidQuote;
use App\Domain\Pricing\Exceptions\PricingTaxCoverageMissing;
use App\Domain\Pricing\Rules\MatchTaxDestination;
use Illuminate\Support\Facades\DB;

final class CalculateTax
{
    public function __construct(private readonly MatchTaxDestination $matcher = new MatchTaxDestination) {}

    public function apply(PriceQuote $quote, TaxContext $context): PriceQuote
    {
        $byId = [];
        foreach ($context->lines as $line) {
            $byId[$line->lineId] = $line;
        }
        $rules = $this->activeRules();
        $allocations = [];
        $taxTotal = 0;
        foreach ($quote->lines as $line) {
            $meta = $byId[$line->lineId] ?? null;
            if (! $meta instanceof TaxableLine) {
                throw new PricingInvalidQuote;
            }
            $destination = $this->destinationFor($meta, $context);
            $match = $this->bestRule($rules, $meta->classKey, $destination);
            $basis = $line->lineTotal;
            $tax = $this->taxAmount($basis, $match['rate_numerator'], $match['rate_denominator'], $context->priceMode);
            $allocations[] = new TaxAllocation(
                $line->lineId,
                $meta->classKey,
                $match['id'],
                $match['rate_numerator'],
                $match['rate_denominator'],
                $basis,
                $basis->withAmount($tax),
                $destination,
            );
            $taxTotal = $this->add($taxTotal, $tax);
        }
        if ($quote->shipping->amount > 0) {
            if ($context->shippingTaxClassKey === null || $context->shipping === null) {
                throw new PricingTaxCoverageMissing('Shipping tax coverage is missing.');
            }
            $match = $this->bestRule($rules, $context->shippingTaxClassKey, $context->shipping);
            $tax = $this->taxAmount($quote->shipping, $match['rate_numerator'], $match['rate_denominator'], $context->priceMode);
            $allocations[] = new TaxAllocation(
                'shipping',
                $context->shippingTaxClassKey,
                $match['id'],
                $match['rate_numerator'],
                $match['rate_denominator'],
                $quote->shipping,
                $quote->shipping->withAmount($tax),
                $context->shipping,
            );
            $taxTotal = $this->add($taxTotal, $tax);
        }
        $taxMoney = $quote->shipping->withAmount($taxTotal);
        $grand = $context->priceMode === 'inclusive'
            ? $quote->discountedMerchandise->plus($quote->shipping)
            : $quote->discountedMerchandise->plus($quote->shipping)->plus($taxMoney);

        return new PriceQuote(
            $quote->lines,
            $quote->merchandiseSubtotal,
            $quote->merchandiseDiscount,
            $quote->discountedMerchandise,
            $quote->shipping,
            $taxMoney,
            $grand,
            $quote->quotedAt,
            $quote->fingerprint,
            $context->priceMode,
            $allocations,
        );
    }

    /**
     * @return list<array{id: string, class_key: string, country_code: string, region_code: ?string, postal_pattern: ?string, rate_numerator: int, rate_denominator: int}>
     */
    private function activeRules(): array
    {
        $rules = [];
        foreach (DB::table('tax_rules')->where('status', 'active')->orderBy('id')->get() as $row) {
            $rules[] = [
                'id' => $this->string($row, 'id'),
                'class_key' => $this->string($row, 'class_key'),
                'country_code' => $this->string($row, 'country_code'),
                'region_code' => $this->nullable($row, 'region_code'),
                'postal_pattern' => $this->nullable($row, 'postal_pattern'),
                'rate_numerator' => $this->int($row, 'rate_numerator'),
                'rate_denominator' => $this->int($row, 'rate_denominator'),
            ];
        }

        return $rules;
    }

    private function destinationFor(TaxableLine $line, TaxContext $context): TaxDestination
    {
        if ($line->fulfillment === 'physical') {
            if ($context->shipping === null) {
                throw new PricingTaxCoverageMissing('Physical lines need a shipping destination.');
            }

            return $context->shipping;
        }
        if ($context->billing === null) {
            throw new PricingTaxCoverageMissing('Digital lines need a billing destination.');
        }

        return $context->billing;
    }

    /**
     * @param  list<array{id: string, class_key: string, country_code: string, region_code: ?string, postal_pattern: ?string, rate_numerator: int, rate_denominator: int}>  $rules
     * @return array{id: string, class_key: string, country_code: string, region_code: ?string, postal_pattern: ?string, rate_numerator: int, rate_denominator: int}
     */
    private function bestRule(array $rules, string $classKey, TaxDestination $destination): array
    {
        $matched = [];
        foreach ($rules as $rule) {
            if ($rule['class_key'] !== $classKey) {
                continue;
            }
            $match = [
                'country_code' => $rule['country_code'],
                'region_code' => $rule['region_code'],
                'postal_pattern' => $rule['postal_pattern'],
            ];
            if ($this->matcher->matches($match, $destination)) {
                $matched[] = ['rule' => $rule, 'specificity' => $this->matcher->specificity($match)];
            }
        }
        if ($matched === []) {
            throw new PricingTaxCoverageMissing('No tax rule covers class '.$classKey.' for this destination.');
        }
        usort($matched, static function (array $left, array $right): int {
            $compare = $right['specificity'] <=> $left['specificity'];

            return $compare !== 0 ? $compare : strcmp($left['rule']['id'], $right['rule']['id']);
        });
        if (count($matched) > 1 && $matched[0]['specificity'] === $matched[1]['specificity']) {
            throw new PricingTaxCoverageMissing('Ambiguous tax coverage cannot be approved.');
        }

        return $matched[0]['rule'];
    }

    private function taxAmount(Money $basis, int $numerator, int $denominator, string $mode): int
    {
        if ($numerator === 0) {
            return 0;
        }
        if ($numerator < 0 || $denominator < 1 || $numerator > $denominator) {
            throw new PricingInvalidQuote;
        }
        if ($mode === 'inclusive') {
            $denominator = $denominator + $numerator;
        }
        $product = bcmul((string) $basis->amount, (string) $numerator, 0);
        $half = bcdiv((string) $denominator, '2', 0);
        $amount = (int) bcdiv(bcadd($product, $half, 0), (string) $denominator, 0);
        if ($amount < 0 || $amount > Money::MAX_SAFE || ($mode === 'exclusive' && $amount > $basis->amount)) {
            throw new PricingInvalidQuote;
        }
        if ($mode === 'inclusive' && $amount > $basis->amount) {
            throw new PricingInvalidQuote;
        }

        return $amount;
    }

    private function add(int $left, int $right): int
    {
        if ($left > Money::MAX_SAFE - $right) {
            throw new PricingInvalidQuote;
        }

        return $left + $right;
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function nullable(object $row, string $key): ?string
    {
        $value = get_object_vars($row)[$key] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
