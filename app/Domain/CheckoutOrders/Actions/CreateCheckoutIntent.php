<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\Catalog\Contracts\InventoryAllocator;
use App\Domain\Catalog\Data\InventoryHold;
use App\Domain\Catalog\Data\InventoryLine;
use App\Domain\CheckoutOrders\Exceptions\CartConflict;
use App\Domain\CheckoutOrders\Exceptions\CheckoutIdempotencyConflict;
use App\Domain\CheckoutOrders\Exceptions\CheckoutIntentConflict;
use App\Domain\CheckoutOrders\Exceptions\CheckoutQuoteStale;
use App\Domain\CheckoutOrders\Support\CartCatalog;
use App\Domain\CheckoutOrders\Support\CartMoney;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\CheckoutOrders\Support\OrderStates;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Pricing\Actions\HoldCoupon;
use App\Domain\Pricing\Data\CouponHold;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateCheckoutIntent
{
    public const HOLD_MINUTES = 30;

    public const OPERATION = 'checkout-session';

    /**
     * @return array<string, mixed>
     */
    public function create(CartOwner $owner, string $quoteId, string $idempotencyKey): array
    {
        if (! Str::isUuid($quoteId) || $quoteId !== strtolower($quoteId)
            || ! Str::isUuid($idempotencyKey) || $idempotencyKey !== strtolower($idempotencyKey)) {
            throw new InvalidArgumentException('Invalid checkout intent.');
        }

        return DB::transaction(function () use ($owner, $quoteId, $idempotencyKey): array {
            $quotes = app(CreateQuote::class);
            $row = $quotes->ownedRow($owner, $quoteId);
            if ($row['superseded_at'] !== null || $row['accepted_at'] === null) {
                throw new CheckoutQuoteStale;
            }
            $expires = new DateTimeImmutable($row['expires_at'], new DateTimeZone('UTC'));
            if (now()->utc() >= $expires) {
                throw new CheckoutQuoteStale('This quote expired. Review the updated totals.');
            }
            $payload = $quotes->payload($row, $quoteId);
            $hash = hash('sha256', json_encode([
                'quote_id' => $quoteId,
                'cart_id' => $row['cart_id'],
                'cart_revision' => $row['cart_revision'],
                'fingerprint' => $row['fingerprint'],
            ], JSON_THROW_ON_ERROR));
            $existingOrder = DB::table('orders')->where('cart_id', $row['cart_id'])
                ->where('cart_revision', $row['cart_revision'])
                ->lockForUpdate()
                ->first();
            if ($existingOrder !== null) {
                $this->assertOwned($owner, $existingOrder);
                $attempt = DB::table('payment_attempts')->where('order_id', $this->string($existingOrder, 'id'))
                    ->orderBy('created_at')
                    ->lockForUpdate()
                    ->first();
                if ($attempt === null) {
                    throw new CheckoutIntentConflict;
                }
                $claim = $this->claim($owner, $idempotencyKey, $hash);
                if (! $claim['replay']) {
                    $this->complete($claim['id'], $this->string($existingOrder, 'id'), $this->string($attempt, 'id'));
                }

                return $this->public($existingOrder, $attempt);
            }
            $quotedAt = new DateTimeImmutable($row['quoted_at'], new DateTimeZone('UTC'));
            try {
                $current = $quotes->compute($owner, $payload['input'], $quotedAt);
            } catch (CartConflict) {
                throw new CheckoutQuoteStale;
            }
            if ($current['fingerprint'] !== $row['fingerprint'] || $current['cart_revision'] !== $row['cart_revision']) {
                throw new CheckoutQuoteStale;
            }
            $claim = $this->claim($owner, $idempotencyKey, $hash);
            if ($claim['replay'] && is_string($claim['resource_id']) && is_string($claim['attempt_id'])) {
                return $this->existing($owner, $claim['resource_id'], $claim['attempt_id']);
            }
            try {
                return $this->freeze($owner, $quoteId, $claim['id'], $current);
            } catch (UniqueConstraintViolationException) {
                $existing = DB::table('orders')->where('cart_id', $current['cart_id'])
                    ->where('cart_revision', $current['cart_revision'])
                    ->lockForUpdate()
                    ->first();
                if ($existing === null) {
                    throw new CheckoutIntentConflict;
                }
                $orderId = $this->string($existing, 'id');
                $this->assertOwned($owner, $existing);
                $attempt = DB::table('payment_attempts')->where('order_id', $orderId)->orderBy('created_at')->lockForUpdate()->first();
                if ($attempt === null) {
                    throw new CheckoutIntentConflict;
                }
                $this->complete($claim['id'], $orderId, $this->string($attempt, 'id'));

                return $this->existing($owner, $orderId, $this->string($attempt, 'id'));
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(CartOwner $owner, string $attemptId): array
    {
        if (! Str::isUuid($attemptId) || $attemptId !== strtolower($attemptId)) {
            throw new CheckoutQuoteStale;
        }
        $attempt = DB::table('payment_attempts')->where('id', $attemptId)->first();
        if ($attempt === null) {
            throw new CheckoutQuoteStale;
        }
        $order = DB::table('orders')->where('id', $this->string($attempt, 'order_id'))->first();
        if ($order === null) {
            throw new CheckoutQuoteStale;
        }
        $this->assertOwned($owner, $order);

        return $this->public($order, $attempt);
    }

    /**
     * @return array{id: string, replay: bool, resource_id: ?string, attempt_id: ?string}
     */
    private function claim(CartOwner $owner, string $key, string $hash): array
    {
        $id = (string) Str::uuid();
        $now = now();
        try {
            DB::table('idempotency_records')->insert([
                'id' => $id,
                'principal_scope' => $owner->principalScope(),
                'operation' => self::OPERATION,
                'idempotency_key' => $key,
                'payload_hash' => $hash,
                'status' => 'processing',
                'resource_id' => null,
                'attempt_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['id' => $id, 'replay' => false, 'resource_id' => null, 'attempt_id' => null];
        } catch (UniqueConstraintViolationException) {
            $existing = DB::table('idempotency_records')
                ->where('principal_scope', $owner->principalScope())
                ->where('operation', self::OPERATION)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($existing === null) {
                throw new CheckoutIntentConflict;
            }
            if ($this->string($existing, 'payload_hash') !== $hash) {
                throw new CheckoutIdempotencyConflict;
            }
            if ($this->string($existing, 'status') !== 'completed'
                || $this->nullable($existing, 'resource_id') === null
                || $this->nullable($existing, 'attempt_id') === null) {
                throw new CheckoutIntentConflict;
            }

            return [
                'id' => $this->string($existing, 'id'),
                'replay' => true,
                'resource_id' => $this->nullable($existing, 'resource_id'),
                'attempt_id' => $this->nullable($existing, 'attempt_id'),
            ];
        }
    }

    /**
     * @param  array{cart_id: string, cart_revision: int, fingerprint: string, input: array<string, mixed>, snapshot: array<string, mixed>}  $current
     * @return array<string, mixed>
     */
    private function freeze(CartOwner $owner, string $quoteId, string $claimId, array $current): array
    {
        $snapshot = $current['snapshot'];
        $grand = $this->money($snapshot['grand_total'] ?? null);
        $merchandise = $this->money($snapshot['merchandise'] ?? null);
        $discount = $this->money($snapshot['discount'] ?? null);
        $shipping = $this->money($snapshot['shipping'] ?? null);
        $tax = $this->money($snapshot['tax'] ?? null);
        $zero = $grand['amount'] === 0;
        $orderId = (string) Str::uuid();
        $attemptId = (string) Str::uuid();
        $now = now();
        $holdUntil = new DateTimeImmutable(
            $now->copy()->utc()->addMinutes(self::HOLD_MINUTES)->format('Y-m-d\\TH:i:s'),
            new DateTimeZone('UTC'),
        );
        $couponCode = is_string($snapshot['coupon'] ?? null) ? (string) $snapshot['coupon'] : null;
        $coupon = $couponCode === null ? null : DB::table('coupons')->where('code_normalized', strtoupper($couponCode))->lockForUpdate()->first();
        DB::table('orders')->insert([
            'id' => $orderId,
            'number' => $this->number(),
            'cart_id' => $current['cart_id'],
            'cart_revision' => $current['cart_revision'],
            'quote_id' => $quoteId,
            'customer_id' => $owner->customerId,
            'guest_token_hash' => $owner->guestTokenHash,
            'email' => is_string($snapshot['email'] ?? null) ? (string) $snapshot['email'] : '',
            'status' => 'pending',
            'financial_status' => 'unpaid',
            'fulfillment_status' => 'unfulfilled',
            'download_status' => 'not_required',
            'currency' => $grand['currency'],
            'exponent' => $grand['exponent'],
            'merchandise_amount' => $merchandise['amount'],
            'discount_amount' => $discount['amount'],
            'shipping_amount' => $shipping['amount'],
            'tax_amount' => $tax['amount'],
            'grand_total_amount' => $grand['amount'],
            'price_mode' => is_string($snapshot['price_mode'] ?? null) ? (string) $snapshot['price_mode'] : 'exclusive',
            'fingerprint' => $current['fingerprint'],
            'coupon_code' => $couponCode,
            'coupon_id' => $coupon === null ? null : $this->string($coupon, 'id'),
            'coupon_revision' => $coupon === null ? null : $this->int($coupon, 'revision'),
            'shipping_method_id' => is_string($snapshot['shipping_method_id'] ?? null) ? (string) $snapshot['shipping_method_id'] : null,
            'zero_total' => $zero,
            'payload' => Crypt::encryptString(json_encode([
                'quote_id' => $quoteId,
                'snapshot' => $snapshot,
            ], JSON_THROW_ON_ERROR)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $kinds = $this->insertLines($orderId, $current['snapshot'], $now);
        DB::table('orders')->where('id', $orderId)->update(OrderStates::initial($zero, $kinds['physical'], $kinds['digital']));
        $this->insertAddress($orderId, 'billing', $snapshot['billing'] ?? null, $now);
        if (($snapshot['shipping_address'] ?? null) !== null) {
            $this->insertAddress($orderId, 'shipping', $snapshot['shipping_address'], $now);
        }
        DB::table('payment_attempts')->insert([
            'id' => $attemptId,
            'order_id' => $orderId,
            'status' => 'created',
            'amount' => $grand['amount'],
            'currency' => $grand['currency'],
            'exponent' => $grand['exponent'],
            'provider' => $zero ? 'local' : null,
            'provider_session_id' => null,
            'expires_at' => $holdUntil->format('Y-m-d H:i:s'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->holdStock($orderId, $holdUntil);
        if ($coupon !== null) {
            app(HoldCoupon::class)->hold(new CouponHold(
                $orderId,
                (string) Str::uuid(),
                $this->string($coupon, 'id'),
                $this->int($coupon, 'revision'),
                $owner->customerId,
                $holdUntil,
            ));
        }
        $this->complete($claimId, $orderId, $attemptId);
        if ($zero) {
            app(CompleteFreeOrder::class)->complete($attemptId);
        }
        app(RecordEvent::class)->record('platform.audit.recorded', 'checkout:intent:'.$orderId, [
            'action' => 'checkout.intent.created',
            'subject_type' => 'order',
            'subject_id' => $orderId,
            'actor_type' => $owner->customerId === null ? 'system' : 'customer',
            'actor_id' => $owner->customerId,
            'metadata' => ['version' => $current['cart_revision']],
        ]);
        $order = DB::table('orders')->where('id', $orderId)->first();
        $attempt = DB::table('payment_attempts')->where('id', $attemptId)->first();
        if ($order === null || $attempt === null) {
            throw new CheckoutIntentConflict;
        }

        return $this->public($order, $attempt);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{physical: bool, digital: bool}
     */
    private function insertLines(string $orderId, array $snapshot, mixed $now): array
    {
        $quoted = is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];
        $byId = [];
        foreach ($quoted as $line) {
            if (is_array($line) && is_string($line['id'] ?? null)) {
                $byId[$line['id']] = $line;
            }
        }
        $cartId = is_string($snapshot['cart_id'] ?? null) ? (string) $snapshot['cart_id'] : '';
        $rows = DB::table('cart_lines')->where('cart_id', $cartId)->orderBy('variant_id')->lockForUpdate()->get();
        $catalog = app(CartCatalog::class);
        $physical = false;
        $digital = false;
        foreach ($rows as $row) {
            $lineId = $this->string($row, 'id');
            $variantId = $this->string($row, 'variant_id');
            $variant = $catalog->variant($variantId);
            if ($variant === null) {
                throw new CheckoutQuoteStale;
            }
            $quotedLine = $byId[$lineId] ?? [];
            $subtotal = $this->money($quotedLine['subtotal'] ?? null);
            $lineDiscount = $this->money($quotedLine['discount'] ?? null);
            $total = $this->money($quotedLine['total'] ?? null);
            $type = $variant['type'] === 'digital' ? 'digital' : 'physical';
            $physical = $physical || $type === 'physical';
            $digital = $digital || $type === 'digital';
            DB::table('order_lines')->insert([
                'id' => (string) Str::uuid(),
                'order_id' => $orderId,
                'cart_line_id' => $lineId,
                'variant_id' => $variantId,
                'product_id' => $variant['product_id'],
                'type' => $type,
                'sku' => $variant['sku'],
                'title' => $variant['title'],
                'quantity' => $this->int($row, 'quantity'),
                'subtotal_amount' => $subtotal['amount'],
                'discount_amount' => $lineDiscount['amount'],
                'total_amount' => $total['amount'],
                'tax_amount' => $this->money($quotedLine['tax'] ?? [
                    'amount' => 0,
                    'currency' => $subtotal['currency'],
                    'exponent' => $subtotal['exponent'],
                ])['amount'],
                'tax_class_key' => $variant['tax_class_key'],
                'digital_asset_version_id' => $variant['type'] === 'digital' ? $this->digitalVersion($variantId) : null,
                'created_at' => $now,
            ]);
        }

        return ['physical' => $physical, 'digital' => $digital];
    }

    private function insertAddress(string $orderId, string $kind, mixed $address, mixed $now): void
    {
        if (! is_array($address)) {
            return;
        }
        $recipient = is_string($address['recipient'] ?? null) ? (string) $address['recipient'] : '';
        $line1 = is_string($address['line1'] ?? null) ? (string) $address['line1'] : '';
        $city = is_string($address['city'] ?? null) ? (string) $address['city'] : '';
        $country = is_string($address['country_code'] ?? null) ? (string) $address['country_code'] : '';
        if ($recipient === '' || $line1 === '' || $city === '' || $country === '') {
            throw new CheckoutQuoteStale;
        }
        DB::table('order_addresses')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $orderId,
            'kind' => $kind,
            'recipient' => $recipient,
            'line1' => $line1,
            'line2' => is_string($address['line2'] ?? null) ? (string) $address['line2'] : null,
            'city' => $city,
            'region' => is_string($address['region'] ?? null) ? (string) $address['region'] : null,
            'postal_code' => is_string($address['postal_code'] ?? null) ? (string) $address['postal_code'] : null,
            'country_code' => $country,
            'created_at' => $now,
        ]);
    }

    private function holdStock(string $orderId, DateTimeImmutable $expires): void
    {
        $lines = $this->trackedLines($orderId);
        if ($lines === []) {
            return;
        }
        app(InventoryAllocator::class)->hold(new InventoryHold($orderId, (string) Str::uuid(), $expires, $lines));
    }

    /** @return list<InventoryLine> */
    private function trackedLines(string $orderId): array
    {
        $lines = [];
        $catalog = app(CartCatalog::class);
        foreach (DB::table('order_lines')->where('order_id', $orderId)->orderBy('variant_id')->get() as $row) {
            $variant = $catalog->variant($this->string($row, 'variant_id'));
            if ($variant !== null && $variant['tracks_inventory'] && $variant['type'] !== 'digital') {
                $lines[] = new InventoryLine($variant['variant_id'], $this->int($row, 'quantity'));
            }
        }

        return $lines;
    }

    private function digitalVersion(string $variantId): ?string
    {
        $id = DB::table('variant_digital_assets')
            ->join('digital_asset_versions', 'variant_digital_assets.digital_asset_version_id', '=', 'digital_asset_versions.id')
            ->where('variant_digital_assets.variant_id', $variantId)
            ->where('digital_asset_versions.status', 'ready')
            ->orderBy('digital_asset_versions.id')
            ->value('digital_asset_versions.id');

        return is_string($id) ? $id : null;
    }

    private function complete(string $claimId, string $orderId, string $attemptId): void
    {
        DB::table('idempotency_records')->where('id', $claimId)->update([
            'status' => 'completed',
            'resource_id' => $orderId,
            'attempt_id' => $attemptId,
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function existing(CartOwner $owner, string $orderId, string $attemptId): array
    {
        $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        $attempt = DB::table('payment_attempts')->where('id', $attemptId)->lockForUpdate()->first();
        if ($order === null || $attempt === null) {
            throw new CheckoutIntentConflict;
        }
        $this->assertOwned($owner, $order);

        return $this->public($order, $attempt);
    }

    private function assertOwned(CartOwner $owner, object $order): void
    {
        if ($owner->customerId !== null) {
            if ($this->nullable($order, 'customer_id') !== $owner->customerId) {
                throw new CheckoutQuoteStale;
            }

            return;
        }
        if ($this->nullable($order, 'guest_token_hash') !== $owner->guestTokenHash) {
            throw new CheckoutQuoteStale;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function public(object $order, object $attempt): array
    {
        $currency = $this->string($order, 'currency');
        $exponent = $this->int($order, 'exponent');
        $amount = $this->int($order, 'grand_total_amount');
        $expires = new DateTimeImmutable($this->string($attempt, 'expires_at'), new DateTimeZone('UTC'));

        return [
            'id' => $this->string($attempt, 'id'),
            'order_id' => $this->string($order, 'id'),
            'order_number' => $this->string($order, 'number'),
            'order_status' => $this->string($order, 'status'),
            'attempt_status' => $this->string($attempt, 'status'),
            'cart_id' => $this->string($order, 'cart_id'),
            'cart_revision' => $this->int($order, 'cart_revision'),
            'fingerprint' => $this->string($order, 'fingerprint'),
            'zero_total' => $this->int($order, 'zero_total') === 1 || $this->string($order, 'zero_total') === '1',
            'grand_total' => CartMoney::format($amount, $currency, $exponent),
            'expires_at' => $expires->format('Y-m-d\\TH:i:s\\Z'),
            'provider_redirect' => null,
        ];
    }

    /**
     * @return array{amount: int, currency: string, exponent: int}
     */
    private function money(mixed $value): array
    {
        if (! is_array($value) || ! is_numeric($value['amount'] ?? null) || ! is_string($value['currency'] ?? null) || ! is_numeric($value['exponent'] ?? null)) {
            throw new CheckoutQuoteStale;
        }

        return [
            'amount' => (int) $value['amount'],
            'currency' => (string) $value['currency'],
            'exponent' => (int) $value['exponent'],
        ];
    }

    private function number(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $number = 'M'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 12));
            if (! DB::table('orders')->where('number', $number)->exists()) {
                return $number;
            }
        }

        throw new CheckoutIntentConflict('Unable to allocate an order number.');
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function nullable(object $row, string $key): ?string
    {
        $value = $this->string($row, $key);

        return $value === '' ? null : $value;
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return is_numeric($value) ? (int) $value : 0;
    }
}
