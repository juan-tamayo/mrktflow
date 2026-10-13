<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Catalog\Support\StoreCurrency;
use App\Domain\Pricing\Contracts\PriceCalculator;
use App\Domain\Pricing\Data\Money;
use App\Domain\Pricing\Data\PricedLine;
use App\Domain\Pricing\Data\PriceRequest;
use App\Domain\Pricing\Data\TaxableLine;
use App\Domain\Pricing\Data\TaxContext;
use App\Domain\Pricing\Data\TaxDestination;
use App\Domain\Pricing\Exceptions\PricingInvalidQuote;
use App\Domain\Pricing\Exceptions\PricingTaxCoverageMissing;
use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PreviewTax
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, reason: string|null, mode: string, tax: int, grandTotal: int, cart: string}
     */
    public function preview(array $input): array
    {
        $settings = app(ReadTaxRules::class)->staffRules();
        $cart = is_string($input['cart'] ?? null) ? (string) $input['cart'] : 'physical';
        if (! in_array($cart, ['physical', 'digital', 'mixed'], true)) {
            throw new PricingInvalidQuote;
        }
        $currency = app(StoreCurrency::class)->current();
        $physicalAmount = is_numeric($input['physical_amount'] ?? null) ? (int) $input['physical_amount'] : 0;
        $digitalAmount = is_numeric($input['digital_amount'] ?? null) ? (int) $input['digital_amount'] : 0;
        $shippingAmount = is_numeric($input['shipping_amount'] ?? null) ? (int) $input['shipping_amount'] : 0;
        $physicalClass = is_string($input['physical_class_key'] ?? null) ? (string) $input['physical_class_key'] : 'standard';
        $digitalClass = is_string($input['digital_class_key'] ?? null) ? (string) $input['digital_class_key'] : 'standard';
        $lines = [];
        $taxable = [];
        if ($cart !== 'digital') {
            $physicalId = (string) Str::uuid();
            $lines[] = new PricedLine($physicalId, (string) Str::uuid(), 1, Money::of(max($physicalAmount, 0), $currency['currency'], $currency['exponent']));
            $taxable[] = new TaxableLine($physicalId, $physicalClass, 'physical');
        }
        if ($cart !== 'physical') {
            $digitalId = (string) Str::uuid();
            $lines[] = new PricedLine($digitalId, (string) Str::uuid(), 1, Money::of(max($digitalAmount, 0), $currency['currency'], $currency['exponent']));
            $taxable[] = new TaxableLine($digitalId, $digitalClass, 'digital');
        }
        $shipping = Money::of(max($shippingAmount, 0), $currency['currency'], $currency['exponent']);
        $untaxed = app(PriceCalculator::class)->quote(new PriceRequest(
            $lines,
            new DateTimeImmutable('now', new \DateTimeZone('UTC')),
            null,
            $shipping,
        ));
        try {
            $context = new TaxContext(
                $taxable,
                $settings['price_mode'],
                $this->optionalDestination(
                    $input['billing_country_code'] ?? null,
                    $input['billing_region_code'] ?? null,
                    $input['billing_postal_code'] ?? null,
                ),
                $this->optionalDestination(
                    $input['shipping_country_code'] ?? null,
                    $input['shipping_region_code'] ?? null,
                    $input['shipping_postal_code'] ?? null,
                ),
                $settings['shipping_tax_class_key'],
            );
            $quoted = app(CalculateTax::class)->apply($untaxed, $context);
        } catch (PricingTaxCoverageMissing|PricingInvalidQuote|InvalidArgumentException $exception) {
            return [
                'ok' => false,
                'reason' => $exception->getMessage(),
                'mode' => $settings['price_mode'],
                'tax' => 0,
                'grandTotal' => 0,
                'cart' => $cart,
            ];
        }

        return [
            'ok' => true,
            'reason' => null,
            'mode' => $quoted->priceMode,
            'tax' => $quoted->tax->amount,
            'grandTotal' => $quoted->grandTotal->amount,
            'cart' => $cart,
        ];
    }

    private function optionalDestination(mixed $country, mixed $region, mixed $postal): ?TaxDestination
    {
        if (! is_string($country) || $country === '') {
            return null;
        }

        return new TaxDestination(
            $country,
            is_string($region) ? $region : null,
            is_string($postal) ? $postal : null,
        );
    }
}
