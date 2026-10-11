<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Data\OutboxEvent;
use App\Domain\Platform\Support\OutboxConsumers;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class ConsumeEvent
{
    public function __construct(private readonly OutboxConsumers $consumers) {}

    public function consume(string $eventId, string $consumerId): void
    {
        try {
            DB::transaction(function () use ($eventId, $consumerId): void {
                // Lock the event before its receipt consistently across all consumers.
                $event = DB::table('outbox_events')->where('id', $eventId)->lockForUpdate()->first();
                $receipt = DB::table('consumer_receipts')->where('event_id', $eventId)
                    ->where('consumer', $consumerId)->lockForUpdate()->first();
                if ($event === null || $receipt === null || $receipt->status !== 'pending'
                    || now()->lt($receipt->available_at)) {
                    return;
                }
                $json = Crypt::decryptString($event->payload);
                if ($event->schema_version !== 1 || ! hash_equals($event->payload_digest, hash('sha256', $event->event_type."\n1\n".$json))) {
                    throw new RuntimeException('Invalid event integrity or schema version.');
                }
                $payload = json_decode($json, true, 22, JSON_THROW_ON_ERROR);
                if (! is_array($payload)) {
                    throw new RuntimeException('Invalid event payload.');
                }
                $class = $this->consumers->forEvent($event->event_type)[$consumerId] ?? null;
                if ($class === null) {
                    throw new RuntimeException('Event consumer is no longer registered.');
                }
                app($class)->handle(new OutboxEvent(
                    $event->id, $event->event_type, $event->schema_version,
                    $payload, $event->correlation_id, $event->occurred_at,
                ));
                DB::table('consumer_receipts')->where('id', $receipt->id)->update([
                    'status' => 'complete', 'attempts' => $receipt->attempts + 1,
                    'completed_at' => now(), 'queued_until' => null, 'last_error_code' => null, 'updated_at' => now(),
                ]);
                if (! DB::table('consumer_receipts')->where('event_id', $eventId)->where('status', '!=', 'complete')->exists()) {
                    DB::table('outbox_events')->where('id', $eventId)->update(['published_at' => now()]);
                }
            });
        } catch (Throwable $exception) {
            $this->recordFailure($eventId, $consumerId, $exception);
        }
    }

    private function recordFailure(string $eventId, string $consumerId, Throwable $exception): void
    {
        DB::transaction(function () use ($eventId, $consumerId, $exception): void {
            $event = DB::table('outbox_events')->where('id', $eventId)->lockForUpdate()->first();
            $receipt = DB::table('consumer_receipts')->where('event_id', $eventId)
                ->where('consumer', $consumerId)->lockForUpdate()->first();
            // A competing worker may have completed the work after our rollback.
            if ($event === null || $receipt === null || $receipt->status !== 'pending'
                || now()->lt($receipt->available_at)) {
                return;
            }
            $attempts = $receipt->attempts + 1;
            $failed = $attempts >= config()->integer('outbox.max_attempts');
            $backoff = config()->array('outbox.backoff');
            $delay = $backoff[min($attempts - 1, count($backoff) - 1)];
            DB::table('consumer_receipts')->where('id', $receipt->id)->update([
                'status' => $failed ? 'failed' : 'pending', 'attempts' => $attempts,
                'available_at' => now()->addSeconds($delay), 'queued_until' => null,
                'last_error_code' => 'consumer_failed', 'updated_at' => now(),
            ]);
            Log::warning('outbox.consumer_failed', [
                'event_id' => $eventId, 'consumer' => $consumerId, 'attempt' => $attempts,
                'correlation_id' => $event->correlation_id, 'error_code' => 'consumer_failed',
                'exception_class' => $exception::class, 'quarantined' => $failed,
            ]);
        });
    }
}
