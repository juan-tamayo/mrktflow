<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\CheckoutOrders\Contracts\PaymentGateway;
use App\Domain\CheckoutOrders\Data\HostedCheckoutCharge;
use App\Domain\CheckoutOrders\Data\HostedCheckoutResult;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Exceptions\CheckoutIntentConflict;
use App\Domain\CheckoutOrders\Exceptions\CheckoutQuoteStale;
use App\Domain\CheckoutOrders\Support\CartOwner;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class StartPayment
{
    /** @return array<string, mixed> */
    public function start(CartOwner $owner, string $attemptId): array
    {
        if (! Str::isUuid($attemptId) || $attemptId !== strtolower($attemptId)) {
            throw new CheckoutQuoteStale;
        }
        $prepared = $this->prepare($owner, $attemptId);
        if ($prepared['skip'] === true) {
            return $prepared['view'];
        }
        $gateway = app(PaymentGateway::class);
        $intent = $prepared['intent'];
        $charge = $prepared['charge'];
        $sessionId = $prepared['session_id'];
        $result = is_string($sessionId) && $sessionId !== ''
            ? $gateway->retrieveSession($intent, $sessionId)
            : $gateway->createSession($intent, $charge);

        return DB::transaction(function () use ($owner, $attemptId, $result): array {
            $attempt = DB::table('payment_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            if ($attempt === null) {
                throw new CheckoutQuoteStale;
            }
            $this->persist($attemptId, $attempt, $result);
            $view = app(CreateCheckoutIntent::class)->snapshot($owner, $attemptId);
            $view['provider_redirect'] = $this->hostedUrl(DB::table('payment_attempts')->where('id', $attemptId)->first());

            return $view;
        });
    }

    /**
     * @return array{skip: true, view: array<string, mixed>}|array{skip: false, intent: PaymentIntent, charge: HostedCheckoutCharge, session_id: ?string, status: string}
     */
    private function prepare(CartOwner $owner, string $attemptId): array
    {
        return DB::transaction(function () use ($owner, $attemptId): array {
            $view = app(CreateCheckoutIntent::class)->snapshot($owner, $attemptId);
            $attempt = DB::table('payment_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            $order = $attempt === null ? null : DB::table('orders')->where('id', $this->string($attempt, 'order_id'))->lockForUpdate()->first();
            if ($attempt === null || $order === null) {
                throw new CheckoutQuoteStale;
            }
            if ($this->bool($order, 'zero_total')) {
                app(CompleteFreeOrder::class)->complete($attemptId);
                $view = app(CreateCheckoutIntent::class)->snapshot($owner, $attemptId);

                return ['skip' => true, 'view' => $view];
            }
            if ($this->string($attempt, 'status') === 'succeeded') {
                return ['skip' => true, 'view' => $view];
            }
            $status = $this->string($attempt, 'status');
            if (in_array($status, ['failed', 'expired'], true)) {
                throw new CheckoutIntentConflict('This payment can no longer be started.');
            }
            $existingUrl = $this->hostedUrl($attempt);
            if ($status === 'awaiting_payment' && $this->nullable($attempt, 'provider_session_id') !== null && $existingUrl !== null) {
                $view['provider_redirect'] = $existingUrl;

                return ['skip' => true, 'view' => $view];
            }
            $charge = $this->charge($order, $attempt, $attemptId);
            $hash = $charge->payloadHash();
            $stored = $this->nullable($attempt, 'outbound_hash');
            if ($stored !== null && $stored !== $hash) {
                throw new CheckoutIntentConflict('This payment attempt cannot change its frozen total.');
            }
            DB::table('payment_attempts')->where('id', $attemptId)->update([
                'provider' => 'stripe',
                'provider_account' => $charge->intent->accountId,
                'live' => $charge->intent->live,
                'outbound_hash' => $hash,
                'updated_at' => now(),
            ]);

            return [
                'skip' => false,
                'intent' => $charge->intent,
                'charge' => $charge,
                'session_id' => $this->nullable($attempt, 'provider_session_id'),
                'status' => $status,
            ];
        });
    }

    private function persist(string $attemptId, object $attempt, HostedCheckoutResult $result): void
    {
        $status = $this->string($attempt, 'status');
        if ($status === 'awaiting_payment' && $this->nullable($attempt, 'provider_session_id') !== null) {
            return;
        }
        $session = $result->session;
        $values = ['updated_at' => now()];
        if ($session->state === 'open' && $result->hostedUrl !== null && is_string($session->sessionId)) {
            $values['status'] = 'awaiting_payment';
            $values['provider'] = 'stripe';
            $values['provider_session_id'] = $session->sessionId;
            $values['hosted_url'] = Crypt::encryptString($result->hostedUrl);
            if (is_int($session->expiresAt) && $session->expiresAt > 0) {
                $values['expires_at'] = (new DateTimeImmutable('@'.$session->expiresAt))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        } elseif ($status !== 'awaiting_payment') {
            $values['status'] = 'unknown';
            $values['provider'] = 'stripe';
        }
        DB::table('payment_attempts')->where('id', $attemptId)->update($values);
    }

    private function charge(object $order, object $attempt, string $attemptId): HostedCheckoutCharge
    {
        $payload = json_decode(Crypt::decryptString($this->string($order, 'payload')), true);
        if (! is_array($payload) || ! is_array($payload['snapshot'] ?? null)) {
            throw new InvalidArgumentException('Invalid hosted checkout charge.');
        }
        $account = config('services.stripe.account');
        $secret = config('services.stripe.secret');
        if (! is_string($account) || $account === '' || ! is_string($secret) || $secret === '') {
            throw new InvalidArgumentException('Hosted payment is not configured.');
        }
        $live = config('services.stripe.live') === true;
        $intent = new PaymentIntent(
            $attemptId,
            $this->int($order, 'grand_total_amount'),
            $this->string($order, 'currency'),
            $account,
            $live,
        );
        $expires = new DateTimeImmutable($this->string($attempt, 'expires_at'), new DateTimeZone('UTC'));
        $base = rtrim((string) config('app.url'), '/');

        return HostedCheckoutCharge::fromSnapshot(
            $intent,
            $payload['snapshot'],
            $base.'/checkout/payment/'.$attemptId.'/return',
            $base.'/checkout/payment/'.$attemptId.'/cancel',
            $expires->getTimestamp(),
        );
    }

    private function hostedUrl(mixed $attempt): ?string
    {
        if (! is_object($attempt)) {
            return null;
        }
        $cipher = $this->nullable($attempt, 'hosted_url');
        if ($cipher === null) {
            return null;
        }
        try {
            $url = Crypt::decryptString($cipher);
        } catch (\Throwable) {
            return null;
        }

        return $url === '' ? null : $url;
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

        return is_numeric($value) ? (int) $value : 0;
    }

    private function bool(object $row, string $key): bool
    {
        $value = get_object_vars($row)[$key] ?? null;

        return $value === true || $value === 1 || $value === '1';
    }
}
