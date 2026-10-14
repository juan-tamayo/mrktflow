<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Support;

use App\Domain\CheckoutOrders\Exceptions\ProviderWebhookRejected;
use Illuminate\Support\Carbon;

final class StripeWebhookSignature
{
    public static function verify(string $payload, ?string $header, string $secret, int $toleranceSeconds = 300): void
    {
        if ($payload === '' || $secret === '' || $header === null || $header === '') {
            throw new ProviderWebhookRejected;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$name, $value] = $pair;
            if ($name === 't' && ctype_digit($value)) {
                $timestamp = $value;
            }
            if ($name === 'v1' && preg_match('/^[0-9a-f]{64}$/D', $value) === 1) {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || $signatures === []) {
            throw new ProviderWebhookRejected;
        }
        $now = Carbon::now()->getTimestamp();
        if (abs($now - (int) $timestamp) > $toleranceSeconds) {
            throw new ProviderWebhookRejected;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new ProviderWebhookRejected;
    }
}
