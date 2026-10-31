<?php

declare(strict_types=1);

namespace App\Domain\Platform\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * Purpose-scoped signed one-click unsubscribe tokens (PLAT-07, MKT-02).
 *
 * A token is a stateless HMAC-signed payload naming one contact and one
 * purpose — nothing else. It authorizes exactly one kind of transition,
 * revoking that purpose, so a leaked or replayed token can never
 * re-enroll anyone or touch another purpose, and applying it twice is
 * the same as applying it once. No token store exists to expire or leak;
 * the signature is the whole credential, verified with the app key.
 */
final class UnsubscribeTokens
{
    public function issue(string $contactId, string $purpose): string
    {
        if (! Str::isUuid($contactId) || ! in_array($purpose, DeliveryPolicy::PURPOSES, true)) {
            throw new InvalidArgumentException('Unsubscribe tokens require a contact and a known purpose.');
        }
        $payload = $this->encode((string) json_encode(['c' => $contactId, 'p' => $purpose], JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->sign($payload);
    }

    /**
     * @return array{contact_id: string, purpose: string}|null
     */
    public function parse(#[\SensitiveParameter] string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        [$payload, $signature] = $parts;
        if (! hash_equals($this->sign($payload), $signature)) {
            return null;
        }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($data) || ! is_string($data['c'] ?? null) || ! is_string($data['p'] ?? null)
            || ! Str::isUuid($data['c']) || ! in_array($data['p'], DeliveryPolicy::PURPOSES, true)) {
            return null;
        }

        return ['contact_id' => $data['c'], 'purpose' => $data['p']];
    }

    private function sign(string $payload): string
    {
        return $this->encode(hash_hmac('sha256', $payload, $this->key(), true));
    }

    private function key(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }

    private function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
