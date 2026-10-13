<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Catalog\Support\StoreCurrency;
use App\Domain\Pricing\Contracts\PriceCalculator;
use App\Domain\Pricing\Data\CouponBasketLine;
use App\Domain\Pricing\Data\LineAllocation;
use App\Domain\Pricing\Data\MerchandiseDiscount;
use App\Domain\Pricing\Data\Money;
use App\Domain\Pricing\Data\PricedLine;
use App\Domain\Pricing\Data\PriceRequest;
use App\Domain\Pricing\Data\TaxableLine;
use App\Domain\Pricing\Data\TaxContext;
use App\Domain\Pricing\Data\TaxDestination;
use App\Domain\Pricing\Exceptions\PricingCouponExhausted;
use App\Domain\Pricing\Exceptions\PricingCouponRejected;
use App\Domain\Pricing\Exceptions\PricingInvalidQuote;
use App\Domain\Pricing\Exceptions\PricingTaxCoverageMissing;
use App\Domain\Shipping\Contracts\ShippingQuoter;
use App\Domain\Shipping\Data\ShippingDestination;
use App\Domain\Shipping\Data\ShippingQuoteLine;
use App\Domain\Shipping\Data\ShippingQuoteRequest;
use App\Domain\Shipping\Exceptions\ShippingInvalidQuote;
use App\Domain\Shipping\Exceptions\ShippingMissingMeasurement;
use App\Domain\Shipping\Exceptions\ShippingNoService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PreviewPricing
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     ok: bool,
     *     reason: ?string,
     *     cart: string,
     *     price_mode: string,
     *     currency: string,
     *     exponent: int,
     *     coupon: ?string,
     *     merchandise: array{amount: int, currency: string, exponent: int, formatted: string},
     *     discount: array{amount: int, currency: string, exponent: int, formatted: string},
     *     shipping: array{amount: int, currency: string, exponent: int, formatted: string},
     *     tax: array{amount: int, currency: string, exponent: int, formatted: string},
     *     grand_total: array{amount: int, currency: string, exponent: int, formatted: string}
     * }
     */
    public function preview(array $input): array
    {
        $cart = is_string($input['cart'] ?? null) ? (string) $input['cart'] : '';
        if (! in_array($cart, ['physical', 'digital', 'mixed'], true)) {
            throw new PricingInvalidQuote;
        }
        $currency = app(StoreCurrency::class)->current();
        $zero = Money::of(0, $currency['currency'], $currency['exponent'])->toArray();
        $failed = function (string $reason) use ($cart, $currency, $zero): array {
            return [
                'ok' => false,
                'reason' => $reason,
                'cart' => $cart,
                'price_mode' => '',
                'currency' => $currency['currency'],
                'exponent' => $currency['exponent'],
                'coupon' => null,
                'merchandise' => $zero,
                'discount' => $zero,
                'shipping' => $zero,
                'tax' => $zero,
                'grand_total' => $zero,
            ];
        };
        $physicalAmount = is_numeric($input['physical_amount'] ?? null) ? (int) $input['physical_amount'] : -1;
        $digitalAmount = is_numeric($input['digital_amount'] ?? null) ? (int) $input['digital_amount'] : -1;
        $physicalGrams = is_numeric($input['physical_grams'] ?? null) ? (int) $input['physical_grams'] : 1200;
        if ($physicalAmount < 0 || $digitalAmount < 0 || $physicalGrams < 1 || $physicalGrams > 1_000_000) {
            return $failed('Enter nonnegative amounts and a physical weight in grams.');
        }
        if ($cart !== 'digital' && $physicalAmount < 1) {
            return $failed('Physical carts need a merchandise amount.');
        }
        if ($cart !== 'physical' && $digitalAmount < 1) {
            return $failed('Digital carts need a merchandise amount.');
        }
        $physicalClass = is_string($input['physical_class_key'] ?? null) ? (string) $input['physical_class_key'] : 'general';
        $digitalClass = is_string($input['digital_class_key'] ?? null) ? (string) $input['digital_class_key'] : 'general';
        $physicalTax = is_string($input['physical_tax_class_key'] ?? null) ? (string) $input['physical_tax_class_key'] : 'standard';
        $digitalTax = is_string($input['digital_tax_class_key'] ?? null) ? (string) $input['digital_tax_class_key'] : 'standard';
        $quotedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $priced = [];
        $coupons = [];
        $taxable = [];
        if ($cart !== 'digital') {
            $physicalId = (string) Str::uuid();
            $physicalLine = new PricedLine($physicalId, (string) Str::uuid(), 1, Money::of($physicalAmount, $currency['currency'], $currency['exponent']));
            $priced[] = $physicalLine;
            $coupons[] = new CouponBasketLine($physicalLine, (string) Str::uuid());
            $taxable[] = new TaxableLine($physicalId, $physicalTax, 'physical');
        }
        if ($cart !== 'physical') {
            $digitalId = (string) Str::uuid();
            $digitalLine = new PricedLine($digitalId, (string) Str::uuid(), 1, Money::of($digitalAmount, $currency['currency'], $currency['exponent']));
            $priced[] = $digitalLine;
            $coupons[] = new CouponBasketLine($digitalLine, (string) Str::uuid());
            $taxable[] = new TaxableLine($digitalId, $digitalTax, 'digital');
        }
        $discount = MerchandiseDiscount::none();
        $waivesShipping = false;
        $couponCode = $input['coupon'] ?? null;
        if ($couponCode === '') {
            $couponCode = null;
        }
        if ($couponCode !== null && ! is_string($couponCode)) {
            return $failed('This coupon cannot be applied.');
        }
        try {
            if ($couponCode !== null) {
                $applied = app(ApplyCoupon::class)->apply($couponCode, $coupons, $quotedAt, null);
                $discount = $applied->discount;
                $waivesShipping = $applied->waivesShipping;
                $couponCode = $applied->code;
            }
            $untaxed = app(PriceCalculator::class)->quote(new PriceRequest($priced, $quotedAt, $discount));
        } catch (PricingCouponRejected|PricingCouponExhausted|PricingInvalidQuote $exception) {
            return $failed($exception->getMessage());
        }
        $byId = [];
        foreach ($untaxed->lines as $allocation) {
            $byId[$allocation->lineId] = $allocation;
        }
        $kinds = [];
        foreach ($taxable as $item) {
            $kinds[$item->lineId] = $item->fulfillment;
        }
        $shippingLines = [];
        foreach ($priced as $line) {
            $allocation = $byId[$line->lineId] ?? null;
            if (! $allocation instanceof LineAllocation) {
                return $failed('This price request cannot be quoted.');
            }
            $kind = $kinds[$line->lineId] ?? '';
            if ($kind === '') {
                return $failed('This price request cannot be quoted.');
            }
            $shippingLines[] = new ShippingQuoteLine(
                $line->lineId,
                $kind,
                $kind === 'digital' ? $digitalClass : $physicalClass,
                $line->quantity,
                $allocation->lineTotal->amount,
                $kind === 'digital' ? null : $physicalGrams,
                $kind === 'digital' ? null : 100,
                $kind === 'digital' ? null : 100,
                $kind === 'digital' ? null : 100,
            );
        }
        $delivery = $this->shippingDestination(
            $input['shipping_country_code'] ?? null,
            $input['shipping_region_code'] ?? null,
            $input['shipping_postal_code'] ?? null,
        );
        $method = $input['shipping_method_id'] ?? null;
        if ($method === '') {
            $method = null;
        }
        if ($method !== null && (! is_string($method) || ! Str::isUuid($method))) {
            return $failed('Choose a shipping method.');
        }
        try {
            $shippingQuote = app(ShippingQuoter::class)->quote(new ShippingQuoteRequest(
                $shippingLines,
                $delivery,
                is_string($method) ? strtolower($method) : null,
            ));
        } catch (ShippingNoService|ShippingInvalidQuote|ShippingMissingMeasurement $exception) {
            return $failed($exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            return $failed($exception->getMessage());
        }
        if ($cart !== 'digital' && $shippingQuote->requiresDeliveryAddress && $delivery === null) {
            return $failed('Enter a delivery address.');
        }
        $charge = $waivesShipping ? 0 : (int) ($shippingQuote->charge ?? 0);
        $shippingMoney = $untaxed->discountedMerchandise->withAmount($charge);
        $settings = app(ReadTaxRules::class)->staffRules();
        $billing = $this->taxDestination(
            $input['billing_country_code'] ?? null,
            $input['billing_region_code'] ?? null,
            $input['billing_postal_code'] ?? null,
        );
        if ($billing === null) {
            return $failed('Enter a billing destination.');
        }
        $shippingTax = null;
        if ($shippingQuote->taxDestination !== null) {
            $shippingTax = new TaxDestination(
                $shippingQuote->taxDestination->countryCode,
                $shippingQuote->taxDestination->regionCode,
                $shippingQuote->taxDestination->postalCode,
            );
        } elseif ($cart === 'digital') {
            $shippingTax = $billing;
        }
        try {
            $taxed = app(PriceCalculator::class)->quote(new PriceRequest(
                $priced,
                $quotedAt,
                $discount,
                $shippingMoney,
                new TaxContext($taxable, $settings['price_mode'], $billing, $shippingTax, $settings['shipping_tax_class_key']),
            ));
        } catch (PricingTaxCoverageMissing|PricingInvalidQuote|InvalidArgumentException $exception) {
            return $failed($exception->getMessage());
        }

        return [
            'ok' => true,
            'reason' => null,
            'cart' => $cart,
            'price_mode' => $taxed->priceMode,
            'currency' => $taxed->grandTotal->currency,
            'exponent' => $taxed->grandTotal->exponent,
            'coupon' => $couponCode,
            'merchandise' => $taxed->merchandiseSubtotal->toArray(),
            'discount' => $taxed->merchandiseDiscount->toArray(),
            'shipping' => $taxed->shipping->toArray(),
            'tax' => $taxed->tax->toArray(),
            'grand_total' => $taxed->grandTotal->toArray(),
        ];
    }

    private function shippingDestination(mixed $country, mixed $region, mixed $postal): ?ShippingDestination
    {
        if (! is_string($country) || $country === '') {
            return null;
        }

        return new ShippingDestination(
            $country,
            is_string($region) ? $region : null,
            is_string($postal) ? $postal : null,
        );
    }

    private function taxDestination(mixed $country, mixed $region, mixed $postal): ?TaxDestination
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
