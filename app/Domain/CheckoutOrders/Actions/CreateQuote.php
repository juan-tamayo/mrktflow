<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\CheckoutOrders\Exceptions\CartConflict;
use App\Domain\CheckoutOrders\Exceptions\CheckoutQuoteStale;
use App\Domain\CheckoutOrders\Support\CartCatalog;
use App\Domain\CheckoutOrders\Support\CartMoney;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Platform\Support\SetupChoices;
use App\Domain\Pricing\Actions\ApplyCoupon;
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
use App\Domain\Pricing\Exceptions\PricingTaxCoverageMissing;
use App\Domain\Shipping\Contracts\ShippingQuoter;
use App\Domain\Shipping\Data\ShippingDestination;
use App\Domain\Shipping\Data\ShippingQuoteLine;
use App\Domain\Shipping\Data\ShippingQuoteRequest;
use App\Domain\Shipping\Exceptions\ShippingNoService;
use App\Domain\Shipping\Support\StoreMoneySettings;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateQuote
{
    public const TTL_MINUTES = 15;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(CartOwner $owner, array $input): array
    {
        $quotedAt = $this->instant(now()->utc());

        return DB::transaction(function () use ($owner, $input, $quotedAt): array {
            $computed = $this->compute($owner, $input, $quotedAt);
            $id = (string) Str::uuid();
            $now = now();
            $expires = $now->copy()->addMinutes(self::TTL_MINUTES);
            DB::table('checkout_quotes')->where('cart_id', $computed['cart_id'])->whereNull('superseded_at')
                ->update(['superseded_at' => $now, 'updated_at' => $now]);
            DB::table('checkout_quotes')->insert([
                'id' => $id,
                'cart_id' => $computed['cart_id'],
                'cart_revision' => $computed['cart_revision'],
                'customer_id' => $owner->customerId,
                'guest_token_hash' => $owner->guestTokenHash,
                'fingerprint' => $computed['fingerprint'],
                'quoted_at' => $quotedAt->format('Y-m-d H:i:s'),
                'expires_at' => $expires,
                'accepted_at' => null,
                'superseded_at' => null,
                'payload' => Crypt::encryptString(json_encode([
                    'quote_id' => $id,
                    'cart_id' => $computed['cart_id'],
                    'input' => $computed['input'],
                    'snapshot' => $computed['snapshot'],
                ], JSON_THROW_ON_ERROR)),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'checkout:quote:'.$id, [
                'action' => 'checkout.quote.created',
                'subject_type' => 'checkout_quote',
                'subject_id' => $id,
                'actor_type' => $owner->customerId === null ? 'system' : 'customer',
                'actor_id' => $owner->customerId,
                'metadata' => ['version' => $computed['cart_revision']],
            ]);

            return $this->publicSnapshot($id, $computed['snapshot'], $expires->utc()->format('Y-m-d\\TH:i:s\\Z'), null);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(CartOwner $owner, string $quoteId): array
    {
        $row = $this->ownedRow($owner, $quoteId);
        $payload = $this->payload($row, $quoteId);
        $expires = $this->time($row, 'expires_at');
        $accepted = $row['accepted_at'] === null ? null : $this->time($row, 'accepted_at');

        return $this->publicSnapshot($quoteId, $payload['snapshot'], $expires, $accepted);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{cart_id: string, cart_revision: int, fingerprint: string, input: array<string, mixed>, snapshot: array<string, mixed>}
     */
    public function compute(CartOwner $owner, array $input, DateTimeImmutable $quotedAt): array
    {
        $data = $this->validated($input);
        $cart = app(ReadCart::class)->snapshot($owner);
        if ($cart['id'] === null || $cart['lines'] === []) {
            throw ValidationException::withMessages(['cart' => 'Add an item before checking out.']);
        }
        if ($cart['revision'] !== $data['expected_revision']) {
            throw new CartConflict;
        }
        foreach ($cart['lines'] as $line) {
            if (! $line['available']) {
                throw ValidationException::withMessages(['cart' => 'An item is no longer available.']);
            }
        }
        $catalog = app(CartCatalog::class);
        $priced = [];
        $coupons = [];
        $taxable = [];
        $physical = false;
        foreach ($cart['lines'] as $line) {
            $variant = $catalog->variant((string) $line['variant_id']);
            if ($variant === null || ! $variant['available']) {
                throw ValidationException::withMessages(['cart' => 'An item is no longer available.']);
            }
            $money = Money::of($variant['price_amount'], $variant['currency'], $variant['exponent']);
            $pricedLine = new PricedLine((string) $line['id'], $variant['variant_id'], (int) $line['quantity'], $money);
            $priced[] = $pricedLine;
            $coupons[] = new CouponBasketLine($pricedLine, $variant['product_id'], $variant['category_ids']);
            $taxable[] = new TaxableLine((string) $line['id'], $variant['tax_class_key'], $variant['type'] === 'digital' ? 'digital' : 'physical');
            if ($variant['type'] === 'physical') {
                $physical = true;
            }
        }
        $discount = MerchandiseDiscount::none();
        $waivesShipping = false;
        $couponCode = $data['coupon'];
        if ($couponCode !== null) {
            $applied = app(ApplyCoupon::class)->apply($couponCode, $coupons, $quotedAt, $owner->customerId);
            $discount = $applied->discount;
            $waivesShipping = $applied->waivesShipping;
            $couponCode = $applied->code;
        }
        $untaxed = app(PriceCalculator::class)->quote(new PriceRequest($priced, $quotedAt, $discount));
        $byId = [];
        foreach ($untaxed->lines as $allocation) {
            $byId[$allocation->lineId] = $allocation;
        }
        $shippingLines = [];
        foreach ($cart['lines'] as $line) {
            $variant = $catalog->variant((string) $line['variant_id']);
            if ($variant === null) {
                throw new InvalidArgumentException('Cart line variant is missing.');
            }
            $allocation = $byId[(string) $line['id']] ?? null;
            if (! $allocation instanceof LineAllocation) {
                throw new InvalidArgumentException('Priced line is missing.');
            }
            $digital = $variant['type'] === 'digital';
            $shippingLines[] = new ShippingQuoteLine(
                (string) $line['id'],
                $digital ? 'digital' : 'physical',
                $variant['shipping_class_key'],
                (int) $line['quantity'],
                $allocation->lineTotal->amount,
                $digital ? null : $variant['weight_grams'],
                $digital ? null : $variant['length_mm'],
                $digital ? null : $variant['width_mm'],
                $digital ? null : $variant['height_mm'],
            );
        }
        $delivery = $physical ? $this->shippingDestination($data['shipping']) : null;
        if ($physical && $data['shipping'] === null) {
            try {
                $shippingQuote = app(ShippingQuoter::class)->quote(new ShippingQuoteRequest($shippingLines, null, $data['shipping_method_id']));
            } catch (ShippingNoService) {
                throw ValidationException::withMessages(['shipping' => 'Enter a delivery address.']);
            }
            if ($shippingQuote->requiresDeliveryAddress) {
                throw ValidationException::withMessages(['shipping' => 'Enter a delivery address.']);
            }
        } else {
            $shippingQuote = app(ShippingQuoter::class)->quote(new ShippingQuoteRequest($shippingLines, $delivery, $data['shipping_method_id']));
        }
        $charge = $waivesShipping ? 0 : (int) ($shippingQuote->charge ?? 0);
        $shippingMoney = $untaxed->discountedMerchandise->withAmount($charge);
        $settings = DB::table('pricing_settings')->where('id', 1)->first();
        if ($settings === null) {
            throw new PricingTaxCoverageMissing;
        }
        $mode = (string) $settings->price_mode;
        $shippingClass = (string) $settings->shipping_tax_class_key;
        $billing = $this->taxDestination($data['billing']);
        $shippingTax = null;
        if ($shippingQuote->taxDestination !== null) {
            $shippingTax = new TaxDestination(
                $shippingQuote->taxDestination->countryCode,
                $shippingQuote->taxDestination->regionCode,
                $shippingQuote->taxDestination->postalCode,
            );
        } elseif (! $physical) {
            $shippingTax = $billing;
        }
        $taxed = app(PriceCalculator::class)->quote(new PriceRequest(
            $priced,
            $quotedAt,
            $discount,
            $shippingMoney,
            new TaxContext($taxable, $mode, $billing, $shippingTax, $shippingClass),
        ));
        $lines = [];
        foreach ($taxed->lines as $allocation) {
            $title = '';
            $sku = '';
            foreach ($cart['lines'] as $line) {
                if ((string) $line['id'] === $allocation->lineId) {
                    $title = (string) $line['title'];
                    $sku = (string) $line['sku'];
                    break;
                }
            }
            $lines[] = [
                'id' => $allocation->lineId,
                'title' => $title,
                'sku' => $sku,
                'quantity' => $allocation->quantity,
                'subtotal' => CartMoney::format($allocation->lineSubtotal->amount, $allocation->lineSubtotal->currency, $allocation->lineSubtotal->exponent),
                'discount' => CartMoney::format($allocation->discount->amount, $allocation->discount->currency, $allocation->discount->exponent),
                'total' => CartMoney::format($allocation->lineTotal->amount, $allocation->lineTotal->currency, $allocation->lineTotal->exponent),
            ];
        }
        $snapshot = [
            'cart_id' => $cart['id'],
            'cart_revision' => $cart['revision'],
            'email' => $data['email'],
            'fingerprint' => $taxed->fingerprint,
            'price_mode' => $taxed->priceMode,
            'requires_delivery' => $shippingQuote->requiresDeliveryAddress,
            'digital_only' => $shippingQuote->digitalOnly,
            'coupon' => $couponCode,
            'merchandise' => CartMoney::format($taxed->merchandiseSubtotal->amount, $taxed->merchandiseSubtotal->currency, $taxed->merchandiseSubtotal->exponent),
            'discount' => CartMoney::format($taxed->merchandiseDiscount->amount, $taxed->merchandiseDiscount->currency, $taxed->merchandiseDiscount->exponent),
            'shipping' => CartMoney::format($taxed->shipping->amount, $taxed->shipping->currency, $taxed->shipping->exponent),
            'tax' => CartMoney::format($taxed->tax->amount, $taxed->tax->currency, $taxed->tax->exponent),
            'grand_total' => CartMoney::format($taxed->grandTotal->amount, $taxed->grandTotal->currency, $taxed->grandTotal->exponent),
            'lines' => $lines,
            'eligible_methods' => $shippingQuote->eligible,
            'billing' => $data['billing'],
            'shipping_address' => $data['shipping'],
            'shipping_method_id' => $shippingQuote->chosen['id'] ?? $data['shipping_method_id'],
        ];

        return [
            'cart_id' => (string) $cart['id'],
            'cart_revision' => $cart['revision'],
            'fingerprint' => $taxed->fingerprint,
            'input' => $data,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function publicSnapshot(string $id, array $snapshot, string $expiresAt, ?string $acceptedAt): array
    {
        return $snapshot + [
            'id' => $id,
            'expires_at' => $expiresAt,
            'accepted_at' => $acceptedAt,
        ];
    }

    /**
     * @return array{id: string, cart_id: string, cart_revision: int, fingerprint: string, quoted_at: string, expires_at: string, accepted_at: ?string, superseded_at: ?string, payload: string}
     */
    public function ownedRow(CartOwner $owner, string $quoteId): array
    {
        if (! Str::isUuid($quoteId)) {
            throw new CheckoutQuoteStale;
        }
        $query = DB::table('checkout_quotes')->where('id', $quoteId)->lockForUpdate();
        if ($owner->customerId !== null) {
            $query->where('customer_id', $owner->customerId);
        } else {
            $query->where('guest_token_hash', $owner->guestTokenHash);
        }
        $row = $query->first();
        if ($row === null) {
            throw new CheckoutQuoteStale;
        }
        $data = get_object_vars($row);
        $id = $data['id'] ?? null;
        $cartId = $data['cart_id'] ?? null;
        $revision = $data['cart_revision'] ?? null;
        $fingerprint = $data['fingerprint'] ?? null;
        $quotedAt = $data['quoted_at'] ?? null;
        $expiresAt = $data['expires_at'] ?? null;
        $payload = $data['payload'] ?? null;
        if (! is_string($id) || ! is_string($cartId) || (! is_int($revision) && ! is_numeric($revision))
            || ! is_string($fingerprint) || ! is_string($quotedAt) || ! is_string($expiresAt) || ! is_string($payload)) {
            throw new CheckoutQuoteStale;
        }

        return [
            'id' => $id,
            'cart_id' => $cartId,
            'cart_revision' => (int) $revision,
            'fingerprint' => $fingerprint,
            'quoted_at' => $quotedAt,
            'expires_at' => $expiresAt,
            'accepted_at' => is_string($data['accepted_at'] ?? null) ? $data['accepted_at'] : null,
            'superseded_at' => is_string($data['superseded_at'] ?? null) ? $data['superseded_at'] : null,
            'payload' => $payload,
        ];
    }

    /**
     * @param  array{payload: string, snapshot?: mixed}  $row
     * @return array{quote_id: string, cart_id: string, input: array<string, mixed>, snapshot: array<string, mixed>}
     */
    public function payload(array $row, string $quoteId): array
    {
        $decoded = json_decode(Crypt::decryptString($row['payload']), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ($decoded['quote_id'] ?? null) !== $quoteId
            || ! is_array($decoded['input'] ?? null) || ! is_array($decoded['snapshot'] ?? null)
            || ! is_string($decoded['cart_id'] ?? null)) {
            throw new CheckoutQuoteStale;
        }

        return [
            'quote_id' => $quoteId,
            'cart_id' => $decoded['cart_id'],
            'input' => $decoded['input'],
            'snapshot' => $decoded['snapshot'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{expected_revision: int, email: string, coupon: ?string, shipping_method_id: ?string, billing: array{recipient: string, line1: string, line2: ?string, city: string, region: ?string, postal_code: ?string, country_code: string}, shipping: ?array{recipient: string, line1: string, line2: ?string, city: string, region: ?string, postal_code: ?string, country_code: string}}
     */
    private function validated(array $input): array
    {
        $revision = $input['expected_revision'] ?? $input['expectedRevision'] ?? null;
        if (! is_int($revision) && ! (is_string($revision) && preg_match('/^\d+$/', $revision) === 1)) {
            throw new CartConflict;
        }
        $revision = (int) $revision;
        if ($revision < 1) {
            throw new CartConflict;
        }
        $email = $input['email'] ?? null;
        if (! is_string($email) || mb_strlen($email) > 254 || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => 'Enter a valid email.']);
        }
        $coupon = $input['coupon'] ?? null;
        if ($coupon === '') {
            $coupon = null;
        }
        if ($coupon !== null && ! is_string($coupon)) {
            throw ValidationException::withMessages(['coupon' => 'This coupon cannot be applied.']);
        }
        $method = $input['shipping_method_id'] ?? $input['shippingMethodId'] ?? null;
        if ($method === '') {
            $method = null;
        }
        if ($method !== null && (! is_string($method) || ! Str::isUuid($method))) {
            throw ValidationException::withMessages(['shipping_method_id' => 'Choose a shipping method.']);
        }
        $billing = $this->address($input['billing'] ?? null, 'billing');
        $shippingInput = $input['shipping'] ?? null;
        $shipping = $shippingInput === null ? null : $this->address($shippingInput, 'shipping');

        return [
            'expected_revision' => $revision,
            'email' => mb_strtolower(trim($email), 'UTF-8'),
            'coupon' => $coupon,
            'shipping_method_id' => $method === null ? null : strtolower($method),
            'billing' => $billing,
            'shipping' => $shipping,
        ];
    }

    /**
     * @return array{recipient: string, line1: string, line2: ?string, city: string, region: ?string, postal_code: ?string, country_code: string}
     */
    private function address(mixed $input, string $field): array
    {
        if (! is_array($input)) {
            throw ValidationException::withMessages([$field => 'Enter a complete address.']);
        }
        $allowed = app(StoreMoneySettings::class)->current()['countries'];
        $countries = array_column(app(SetupChoices::class)->countries(), 'code');
        $recipient = $this->text($input['recipient'] ?? null, 120);
        $line1 = $this->text($input['line1'] ?? null, 200);
        $city = $this->text($input['city'] ?? null, 120);
        $country = is_string($input['country_code'] ?? $input['countryCode'] ?? null)
            ? strtoupper(trim((string) ($input['country_code'] ?? $input['countryCode']))) : '';
        if ($recipient === null || $line1 === null || $city === null || ! in_array($country, $countries, true) || ! in_array($country, $allowed, true)) {
            throw ValidationException::withMessages([$field => 'Enter a complete address in a selling country.']);
        }

        return [
            'recipient' => $recipient,
            'line1' => $line1,
            'line2' => $this->text($input['line2'] ?? null, 200),
            'city' => $city,
            'region' => $this->text($input['region'] ?? null, 120),
            'postal_code' => $this->text($input['postal_code'] ?? $input['postalCode'] ?? null, 32),
            'country_code' => $country,
        ];
    }

    private function text(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen($value) > $max) {
            throw ValidationException::withMessages(['address' => 'Enter a complete address.']);
        }

        return trim($value);
    }

    /**
     * @param  array{country_code: string, region: ?string, postal_code: ?string}|null  $address
     */
    private function shippingDestination(?array $address): ?ShippingDestination
    {
        if ($address === null) {
            return null;
        }

        return new ShippingDestination($address['country_code'], $address['region'], $address['postal_code']);
    }

    /**
     * @param  array{country_code: string, region: ?string, postal_code: ?string}  $address
     */
    private function taxDestination(array $address): TaxDestination
    {
        return new TaxDestination($address['country_code'], $address['region'], $address['postal_code']);
    }

    private function instant(\DateTimeInterface $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time->format('Y-m-d\\TH:i:s'), new DateTimeZone('UTC'));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function time(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (! is_string($value) || $value === '') {
            throw new CheckoutQuoteStale;
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s\\Z');
    }
}
