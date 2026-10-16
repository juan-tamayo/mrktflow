<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Adapters;

use App\Domain\CheckoutOrders\Contracts\PaymentGateway;
use App\Domain\CheckoutOrders\Data\HostedCheckoutCharge;
use App\Domain\CheckoutOrders\Data\HostedCheckoutResult;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\PaymentSession;
use App\Domain\CheckoutOrders\Data\RefundIntent;
use App\Domain\CheckoutOrders\Data\RefundResult;
use App\Domain\CheckoutOrders\Support\HostedCheckoutUrl;
use App\Domain\Platform\Support\OutboundHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;

final class StripeGateway implements PaymentGateway
{
    public function __construct(private OutboundHttp $http) {}

    public function createSession(PaymentIntent $intent, HostedCheckoutCharge $charge): HostedCheckoutResult
    {
        if (! $intent->matches($charge->intent)) {
            throw new InvalidArgumentException('Invalid hosted checkout charge.');
        }

        return $this->observe($intent, $this->send(
            'POST',
            'https://api.stripe.com/v1/checkout/sessions',
            $charge->stripeParameters(),
            $intent->idempotencyKey(),
        ));
    }

    public function retrieveSession(PaymentIntent $intent, ?string $sessionId): HostedCheckoutResult
    {
        if ($sessionId === null || $sessionId === '') {
            return new HostedCheckoutResult(PaymentSession::unknown(), null);
        }

        return $this->observe($intent, $this->send(
            'GET',
            'https://api.stripe.com/v1/checkout/sessions/'.$sessionId,
            [],
            null,
        ));
    }

    public function expireSession(PaymentIntent $intent, string $sessionId): PaymentSession
    {
        return $this->observe($intent, $this->send(
            'POST',
            'https://api.stripe.com/v1/checkout/sessions/'.$sessionId.'/expire',
            [],
            'expire:'.$intent->attemptId,
        ))->session;
    }

    public function refund(RefundIntent $intent): RefundResult
    {
        return $this->observeRefund($intent, $this->send(
            'POST',
            'https://api.stripe.com/v1/refunds',
            [
                'payment_intent' => $intent->captureId,
                'amount' => (string) $intent->amountMinor,
                'metadata' => ['refund_id' => $intent->refundId],
            ],
            $intent->idempotencyKey(),
        ));
    }

    public function retrieveRefund(RefundIntent $intent, ?string $providerRefundId): RefundResult
    {
        if ($providerRefundId !== null && $providerRefundId !== '') {
            return $this->observeRefund($intent, $this->send(
                'GET',
                'https://api.stripe.com/v1/refunds/'.$providerRefundId,
                [],
                null,
            ));
        }
        $payload = $this->send(
            'GET',
            'https://api.stripe.com/v1/refunds',
            ['payment_intent' => $intent->captureId, 'limit' => '100'],
            null,
        );
        if ($payload === null || ! is_array($payload['data'] ?? null)) {
            return RefundResult::unknown();
        }
        foreach ($payload['data'] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
            if (($metadata['refund_id'] ?? null) === $intent->refundId) {
                return $this->observeRefund($intent, $item);
            }
        }

        return RefundResult::unknown();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function send(string $method, string $url, array $body, ?string $idempotency): ?array
    {
        $secret = config('services.stripe.secret');
        if (! is_string($secret) || $secret === '' || parse_url($url, PHP_URL_HOST) !== 'api.stripe.com') {
            return null;
        }
        try {
            $client = $this->http->client()->withBasicAuth($secret, '')->acceptJson();
            $account = config('services.stripe.account');
            $headers = [];
            if (is_string($idempotency) && $idempotency !== '') {
                $headers['Idempotency-Key'] = $idempotency;
            }
            if (is_string($account) && $account !== '') {
                $headers['Stripe-Account'] = $account;
            }
            if ($headers !== []) {
                $client = $client->withHeaders($headers);
            }
            $response = $method === 'GET' ? $client->get($url, $body) : $client->asForm()->post($url, $body);
            if ($response->failed()) {
                return null;
            }
            $payload = $response->json();
        } catch (ConnectionException|RequestException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /** @param  array<string, mixed>|null  $payload */
    private function observe(PaymentIntent $intent, ?array $payload): HostedCheckoutResult
    {
        if ($payload === null) {
            return new HostedCheckoutResult(PaymentSession::unknown(), null);
        }
        try {
            $actual = new PaymentIntent(
                $this->string($payload['client_reference_id'] ?? null),
                $this->int($payload['amount_total'] ?? null),
                strtoupper($this->string($payload['currency'] ?? null)),
                $this->account(),
                $this->bool($payload['livemode'] ?? null),
            );
            $methods = $payload['payment_method_types'] ?? null;
            if (! is_array($methods) || $methods !== ['card'] || $actual->live !== $intent->live) {
                return new HostedCheckoutResult(PaymentSession::unknown(), null);
            }
            $capture = $payload['payment_intent'] ?? null;
            if (is_array($capture)) {
                $capture = $capture['id'] ?? null;
            }
            $session = PaymentSession::observed(
                $intent,
                $actual,
                $this->string($payload['id'] ?? null),
                $this->string($payload['status'] ?? null),
                $this->string($payload['payment_status'] ?? null),
                $this->int($payload['expires_at'] ?? null),
                is_string($capture) ? $capture : null,
            );
            $url = HostedCheckoutUrl::parse($payload['url'] ?? null);
            if ($session->state === 'open' && $url === null) {
                return new HostedCheckoutResult(PaymentSession::unknown(), null);
            }

            return new HostedCheckoutResult($session, $url);
        } catch (InvalidArgumentException) {
            return new HostedCheckoutResult(PaymentSession::unknown(), null);
        }
    }

    /** @param  array<string, mixed>|null  $payload */
    private function observeRefund(RefundIntent $intent, ?array $payload): RefundResult
    {
        if ($payload === null) {
            return RefundResult::unknown();
        }
        try {
            $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
            $refundId = is_string($metadata['refund_id'] ?? null) ? (string) $metadata['refund_id'] : $intent->refundId;
            $actual = new RefundIntent(
                $refundId,
                $intent->payment,
                $this->string($payload['payment_intent'] ?? null),
                $this->int($payload['amount'] ?? null),
            );

            return RefundResult::observed($intent, $actual, $this->string($payload['id'] ?? null), $this->string($payload['status'] ?? null));
        } catch (InvalidArgumentException) {
            return RefundResult::unknown();
        }
    }

    private function account(): string
    {
        $account = config('services.stripe.account');

        return is_string($account) && $account !== '' ? $account : 'acct_missing';
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
