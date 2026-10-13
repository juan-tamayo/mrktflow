<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Data;

use InvalidArgumentException;

final readonly class HostedCheckoutCharge
{
    /** @var array<string, int> */
    private const MINIMUMS = [
        'USD' => 50,
        'EUR' => 50,
        'GBP' => 30,
        'CAD' => 50,
        'AUD' => 50,
        'JPY' => 50,
    ];

    /**
     * @param  list<HostedCheckoutLine>  $lines
     */
    public function __construct(
        public PaymentIntent $intent,
        public array $lines,
        public string $successUrl,
        public string $cancelUrl,
        public int $expiresAt,
    ) {
        if ($lines === [] || count($lines) > 100) {
            throw new InvalidArgumentException('Invalid hosted checkout lines.');
        }
        $sum = 0;
        foreach ($lines as $line) {
            $sum += $line->amountMinor;
        }
        $minimum = self::MINIMUMS[$intent->currency] ?? null;
        if ($minimum === null || $sum !== $intent->amountMinor || $intent->amountMinor < $minimum
            || $intent->amountMinor > 99999999 || $expiresAt < 1
            || ! self::appUrl($successUrl) || ! self::appUrl($cancelUrl)) {
            throw new InvalidArgumentException('Invalid hosted checkout charge.');
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromSnapshot(
        PaymentIntent $intent,
        array $snapshot,
        string $successUrl,
        string $cancelUrl,
        int $expiresAt,
    ): self {
        $mode = is_string($snapshot['price_mode'] ?? null) ? (string) $snapshot['price_mode'] : '';
        if (! in_array($mode, ['exclusive', 'inclusive'], true)) {
            throw new InvalidArgumentException('Invalid hosted checkout charge.');
        }
        $lines = [];
        $quoted = is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];
        foreach ($quoted as $line) {
            if (! is_array($line)) {
                throw new InvalidArgumentException('Invalid hosted checkout charge.');
            }
            $amount = self::money($line['total'] ?? null, $intent);
            if ($amount === 0) {
                continue;
            }
            $title = is_string($line['title'] ?? null) ? trim((string) $line['title']) : '';
            $lines[] = new HostedCheckoutLine($title === '' ? 'Item' : $title, $amount);
        }
        $shipping = self::money($snapshot['shipping'] ?? null, $intent);
        if ($shipping > 0) {
            $lines[] = new HostedCheckoutLine('Shipping', $shipping);
        }
        $tax = self::money($snapshot['tax'] ?? null, $intent);
        if ($mode === 'exclusive' && $tax > 0) {
            $lines[] = new HostedCheckoutLine('Tax', $tax);
        }
        $grand = self::money($snapshot['grand_total'] ?? null, $intent);
        if ($grand !== $intent->amountMinor) {
            throw new InvalidArgumentException('Invalid hosted checkout charge.');
        }

        return new self($intent, $lines, $successUrl, $cancelUrl, $expiresAt);
    }

    /** @return array<string, mixed> */
    public function stripeParameters(): array
    {
        $items = [];
        foreach ($this->lines as $line) {
            $items[] = [
                'quantity' => '1',
                'adjustable_quantity' => ['enabled' => 'false'],
                'price_data' => [
                    'currency' => strtolower($this->intent->currency),
                    'unit_amount' => (string) $line->amountMinor,
                    'product_data' => ['name' => $line->name],
                ],
            ];
        }

        return [
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'success_url' => $this->successUrl,
            'cancel_url' => $this->cancelUrl,
            'client_reference_id' => $this->intent->attemptId,
            'expires_at' => (string) $this->expiresAt,
            'allow_promotion_codes' => 'false',
            'billing_address_collection' => 'auto',
            'automatic_tax' => ['enabled' => 'false'],
            'line_items' => $items,
            'metadata' => ['attempt_id' => $this->intent->attemptId],
            'payment_intent_data' => ['metadata' => ['attempt_id' => $this->intent->attemptId]],
        ];
    }

    public function payloadHash(): string
    {
        return hash('sha256', json_encode($this->stripeParameters(), JSON_THROW_ON_ERROR));
    }

    private static function appUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = is_string($parts['scheme'] ?? null) ? (string) $parts['scheme'] : '';
        $host = is_string($parts['host'] ?? null) ? (string) $parts['host'] : '';
        $loopback = in_array($host, ['127.0.0.1', 'localhost'], true);
        if (! is_array($parts) || ($scheme !== 'https' && ! ($scheme === 'http' && $loopback))
            || $host === '' || isset($parts['user']) || isset($parts['pass']) || $host === 'checkout.stripe.com') {
            return false;
        }
        $path = is_string($parts['path'] ?? null) ? (string) $parts['path'] : '';

        return preg_match('#^/checkout/payment/[0-9a-f-]{36}/(return|cancel)\z#', $path) === 1;
    }

    private static function money(mixed $value, PaymentIntent $intent): int
    {
        if (! is_array($value) || ! is_numeric($value['amount'] ?? null) || ! is_string($value['currency'] ?? null)) {
            throw new InvalidArgumentException('Invalid hosted checkout charge.');
        }
        if ((string) $value['currency'] !== $intent->currency) {
            throw new InvalidArgumentException('Invalid hosted checkout charge.');
        }
        $amount = (int) $value['amount'];
        if ($amount < 0) {
            throw new InvalidArgumentException('Invalid hosted checkout charge.');
        }

        return $amount;
    }
}
