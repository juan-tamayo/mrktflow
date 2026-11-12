<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\CheckoutOrders\Contracts\PaymentGateway;
use App\Domain\CheckoutOrders\Data\HostedCheckoutCharge;
use App\Domain\CheckoutOrders\Data\HostedCheckoutResult;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\PaymentSession;
use App\Domain\CheckoutOrders\Data\RefundIntent;
use App\Domain\CheckoutOrders\Data\RefundResult;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Browser-only captured mixed order so staff can refund one Studio Lamp.
 * FREE100 zero-total orders have no Stripe capture.
 */
final class EnsureBrowserCapturedMixedOrder
{
    public function ensure(): void
    {
        if (! app()->environment('testing')) {
            return;
        }
        $database = (string) config('database.connections.mysql.database');
        if (! in_array($database, ['mrktflow_test', 'mrktflow_browser'], true)) {
            return;
        }
        $customer = CustomerAccount::query()->where('email', 'mixed@example.test')->first();
        if (! $customer instanceof CustomerAccount) {
            return;
        }
        if ($this->capturedOrderId($customer->id) !== null) {
            return;
        }
        $owner = StaffAccount::query()->orderBy('created_at')->first();
        if (! $owner instanceof StaffAccount) {
            throw new RuntimeException('Install a store owner before creating the captured mixed order.');
        }
        $lampVariant = $this->chalkLampVariantId();
        $this->ensureLampStock($owner, $lampVariant);

        $previousStripe = [
            'services.stripe.secret' => config('services.stripe.secret'),
            'services.stripe.account' => config('services.stripe.account'),
            'services.stripe.live' => config('services.stripe.live'),
        ];
        $secret = is_string($previousStripe['services.stripe.secret']) && $previousStripe['services.stripe.secret'] !== ''
            ? $previousStripe['services.stripe.secret']
            : 'sk_test_browser_mixed';
        $account = is_string($previousStripe['services.stripe.account']) && $previousStripe['services.stripe.account'] !== ''
            ? $previousStripe['services.stripe.account']
            : 'acct_browser_mixed';
        config([
            'services.stripe.secret' => $secret,
            'services.stripe.account' => $account,
            'services.stripe.live' => false,
        ]);
        $previousGateway = app(PaymentGateway::class);
        app()->instance(PaymentGateway::class, new BrowserCapturedCheckoutGateway);

        try {
            $cartOwner = CartOwner::customer($customer->id);
            $existing = app(ReadCart::class)->snapshot($cartOwner);
            $cart = app(UpdateCart::class)->replaceLine($cartOwner, [
                'variant_id' => $lampVariant,
                'quantity' => 2,
                'expected_revision' => $existing['revision'],
            ]);
            $quote = app(CreateQuote::class)->create($cartOwner, [
                'expected_revision' => $cart['revision'],
                'email' => $customer->email,
                'coupon' => null,
                'shipping_method_id' => null,
                'billing' => $this->address(),
                'shipping' => $this->address(),
            ]);
            app(AcceptQuote::class)->accept($cartOwner, $quote['id'], $quote['fingerprint'], true);
            $intent = app(CreateCheckoutIntent::class)->create($cartOwner, $quote['id'], (string) Str::uuid());
            app(StartPayment::class)->start($cartOwner, $intent['id']);
            $amount = (int) $intent['grand_total']['amount'];
            $payment = new PaymentIntent($intent['id'], $amount, 'USD', $account, false);
            app(ConfirmOrderPayment::class)->confirm($intent['id'], PaymentSession::observed(
                $payment,
                $payment,
                'cs_test_browser_mixed',
                'complete',
                'paid',
                now()->addHour()->getTimestamp(),
                'pi_test_browser_mixed',
            ));
        } finally {
            app()->instance(PaymentGateway::class, $previousGateway);
            config($previousStripe);
        }

        if ($this->capturedOrderId($customer->id) === null) {
            throw new RuntimeException('The captured mixed order for mixed@example.test was not confirmed.');
        }
    }

    private function capturedOrderId(string $customerId): ?string
    {
        $id = DB::table('orders')
            ->where('customer_id', $customerId)
            ->where('zero_total', 0)
            ->whereIn('status', ['confirmed', 'on_hold', 'completed'])
            ->whereIn('financial_status', ['paid', 'partially_refunded'])
            ->orderByDesc('created_at')
            ->value('id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function chalkLampVariantId(): string
    {
        $productId = DB::table('products')->where('slug', 'studio-lamp')->where('status', 'published')->value('id');
        if (! is_string($productId) || $productId === '') {
            throw new RuntimeException('Studio Lamp must be published before the captured mixed order.');
        }
        $variants = DB::table('product_variants')->where('product_id', $productId)->where('available', true)->orderBy('created_at')->get();
        foreach ($variants as $variant) {
            if (str_contains(mb_strtolower((string) $variant->options, 'UTF-8'), 'chalk')) {
                return (string) $variant->id;
            }
        }
        $default = DB::table('product_variants')->where('product_id', $productId)->where('is_default', true)->value('id');
        if (is_string($default) && $default !== '') {
            return $default;
        }
        throw new RuntimeException('Studio Lamp is missing a purchasable variant.');
    }

    private function ensureLampStock(StaffAccount $owner, string $variantId): void
    {
        $variant = DB::table('product_variants')->where('id', $variantId)->first();
        if ($variant === null || ! (bool) $variant->tracks_inventory) {
            return;
        }
        $balance = DB::table('inventory_balances')->where('variant_id', $variantId)->first();
        $onHand = $balance !== null ? (int) $balance->on_hand : 0;
        $reserved = $balance !== null ? (int) $balance->reserved : 0;
        $needed = $reserved + 2;
        if ($onHand >= $needed) {
            return;
        }
        app(AdjustStock::class)->adjust($owner, [
            'variant_id' => $variantId,
            'operation_key' => (string) Str::uuid(),
            'tracks_inventory' => true,
            'on_hand' => $needed,
            'low_stock_threshold' => 1,
        ]);
    }

    /** @return array{recipient: string, line1: string, line2: null, city: string, region: string, postal_code: string, country_code: string} */
    private function address(): array
    {
        return [
            'recipient' => 'Mixed Shopper',
            'line1' => '10 Market Street',
            'line2' => null,
            'city' => 'Los Angeles',
            'region' => 'CA',
            'postal_code' => '90001',
            'country_code' => 'US',
        ];
    }
}

final class BrowserCapturedCheckoutGateway implements PaymentGateway
{
    public function createSession(PaymentIntent $intent, HostedCheckoutCharge $charge): HostedCheckoutResult
    {
        return $this->open($intent);
    }

    public function retrieveSession(PaymentIntent $intent, ?string $sessionId): HostedCheckoutResult
    {
        return $this->open($intent);
    }

    public function expireSession(PaymentIntent $intent, string $sessionId): PaymentSession
    {
        return PaymentSession::unknown();
    }

    public function refund(RefundIntent $intent): RefundResult
    {
        return RefundResult::unknown();
    }

    public function retrieveRefund(RefundIntent $intent, ?string $providerRefundId): RefundResult
    {
        return RefundResult::unknown();
    }

    private function open(PaymentIntent $intent): HostedCheckoutResult
    {
        return new HostedCheckoutResult(
            PaymentSession::observed($intent, $intent, 'cs_test_browser_mixed', 'open', 'unpaid', now()->addHour()->getTimestamp(), null),
            'https://checkout.stripe.com/c/pay/cs_test_browser_mixed',
        );
    }
}
