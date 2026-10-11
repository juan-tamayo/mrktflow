<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Support\OutboxConsumers;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final class RecordEvent
{
    public function __construct(private readonly OutboxConsumers $consumers) {}

    /** @param array<string, mixed> $payload */
    public function record(string $type, string $intentKey, array $payload, ?string $correlationId = null): string
    {
        $bindings = $this->consumers->forEvent($type);
        if (! preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $type)
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_:.-]{0,190}$/D', $intentKey)
            || ($correlationId !== null && ! Str::isUuid($correlationId))) {
            throw new InvalidArgumentException('Invalid event identifiers.');
        }
        try {
            $json = json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            throw new InvalidArgumentException('Event payload must contain valid JSON data.');
        }
        if (strlen($json) > 65536) {
            throw new InvalidArgumentException('Event payload exceeds the size limit.');
        }
        $digest = hash('sha256', $type."\n1\n".$json);

        return DB::transaction(function () use ($type, $intentKey, $json, $digest, $correlationId, $bindings): string {
            $id = (string) Str::uuid();
            $now = now();
            try {
                DB::table('outbox_events')->insert([
                    'id' => $id, 'event_type' => $type, 'schema_version' => 1, 'intent_key' => $intentKey,
                    'payload' => Crypt::encryptString($json), 'payload_digest' => $digest,
                    'correlation_id' => $correlationId ?? (string) Str::uuid(), 'occurred_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                $existing = DB::table('outbox_events')->where('intent_key', $intentKey)->lockForUpdate()->first();
                if ($existing === null) {
                    throw $exception;
                }
                if (! hash_equals($existing->payload_digest, $digest)) {
                    throw new DomainException('An event intent cannot be changed after recording.');
                }

                return $existing->id;
            }
            foreach (array_keys($bindings) as $consumer) {
                DB::table('consumer_receipts')->insert([
                    'event_id' => $id, 'consumer' => $consumer, 'available_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            return $id;
        });
    }

    private function canonicalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 20) {
            throw new InvalidArgumentException('Event payload exceeds the nesting limit.');
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $this->canonicalize($item, $depth + 1);
            }

            return $value;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)
            || (is_float($value) && is_finite($value))) {
            return $value;
        }
        throw new InvalidArgumentException('Event payload contains unsupported values.');
    }
}
