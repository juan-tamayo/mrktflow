<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\CheckoutOrders\Exceptions\ProviderWebhookRejected;
use App\Domain\CheckoutOrders\Exceptions\ProviderWebhookUnavailable;
use App\Domain\CheckoutOrders\Support\StripeWebhookSignature;
use App\Jobs\ProcessPaymentEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class AcceptProviderEvent
{
    public function accept(string $rawBody, ?string $signatureHeader, ?string $accountHeader): string
    {
        $secret = config('services.stripe.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            throw new ProviderWebhookUnavailable;
        }
        StripeWebhookSignature::verify($rawBody, $signatureHeader, $secret);
        if (strlen($rawBody) > 262144) {
            throw new ProviderWebhookRejected;
        }
        try {
            $decoded = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProviderWebhookRejected;
        }
        if (! is_array($decoded) || ($decoded['object'] ?? null) !== 'event') {
            throw new ProviderWebhookRejected;
        }
        $eventId = $decoded['id'] ?? null;
        $type = $decoded['type'] ?? null;
        $live = $decoded['livemode'] ?? null;
        if (! is_string($eventId) || preg_match('/^evt_[A-Za-z0-9_]+$/D', $eventId) !== 1
            || ! is_string($type) || preg_match('/^[a-z][a-z0-9._]{0,126}$/D', $type) !== 1
            || ! is_bool($live)) {
            throw new ProviderWebhookRejected;
        }
        $expectedLive = config('services.stripe.live') === true;
        if ($live !== $expectedLive) {
            throw new ProviderWebhookRejected;
        }
        $account = $this->account($decoded['account'] ?? null, $accountHeader);
        $expectedAccount = config('services.stripe.account');
        if (! is_string($expectedAccount) || $expectedAccount === '' || $account !== $expectedAccount) {
            throw new ProviderWebhookRejected;
        }
        $environment = $live ? 'live' : 'test';
        $digest = hash('sha256', $rawBody);
        $correlationId = Context::get('correlation_id');
        $correlationId = is_string($correlationId) && Str::isUuid($correlationId) ? $correlationId : (string) Str::uuid();

        try {
            return DB::transaction(function () use ($eventId, $type, $account, $environment, $rawBody, $digest, $correlationId): string {
                $id = (string) Str::uuid();
                $now = now();
                try {
                    DB::table('provider_events')->insert([
                        'id' => $id,
                        'provider' => 'stripe',
                        'account_ref' => $account,
                        'environment' => $environment,
                        'event_id' => $eventId,
                        'event_type' => $type,
                        'processing_state' => 'received',
                        'payload' => Crypt::encryptString($rawBody),
                        'payload_digest' => $digest,
                        'correlation_id' => $correlationId,
                        'received_at' => $now,
                        'next_attempt_at' => $now,
                    ]);
                } catch (UniqueConstraintViolationException $exception) {
                    $existing = DB::table('provider_events')
                        ->where('provider', 'stripe')
                        ->where('account_ref', $account)
                        ->where('environment', $environment)
                        ->where('event_id', $eventId)
                        ->lockForUpdate()
                        ->first();
                    if ($existing === null) {
                        throw $exception;
                    }
                    if (! hash_equals((string) $existing->payload_digest, $digest)) {
                        throw new ProviderWebhookRejected;
                    }
                    $this->dispatchAfterCommit((string) $existing->id);

                    return (string) $existing->id;
                }
                $this->dispatchAfterCommit($id);

                return $id;
            });
        } catch (ProviderWebhookRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ProviderWebhookUnavailable;
        }
    }

    private function account(mixed $payloadAccount, ?string $headerAccount): string
    {
        if (is_string($payloadAccount) && $payloadAccount !== '') {
            return $payloadAccount;
        }
        if (is_string($headerAccount) && $headerAccount !== '') {
            return $headerAccount;
        }
        $configured = config('services.stripe.account');

        return is_string($configured) ? $configured : '';
    }

    private function dispatchAfterCommit(string $eventId): void
    {
        DB::afterCommit(function () use ($eventId): void {
            ProcessPaymentEvent::dispatch($eventId);
        });
    }
}
