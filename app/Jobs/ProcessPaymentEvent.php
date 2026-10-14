<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\CheckoutOrders\Actions\ReconcilePayment;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ProcessPaymentEvent implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public readonly string $eventId)
    {
        $this->onQueue('payments');
    }

    public function handle(): void
    {
        $reconcile = app(ReconcilePayment::class);
        $event = DB::table('provider_events')->where('id', $this->eventId)->first();
        if ($event === null) {
            throw new RuntimeException('Missing provider event.');
        }
        if ($this->string($event, 'processing_state') === 'processed') {
            return;
        }
        try {
            $decoded = json_decode(Crypt::decryptString($this->string($event, 'payload')), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->finish('failed');

            return;
        }
        if (! is_array($decoded)) {
            $this->finish('failed');

            return;
        }
        $object = is_array($decoded['data']['object'] ?? null) ? $decoded['data']['object'] : [];
        $sessionId = is_string($object['id'] ?? null) ? (string) $object['id'] : null;
        $reference = is_string($object['client_reference_id'] ?? null) ? (string) $object['client_reference_id'] : null;
        $attemptId = $this->attemptId($reference, $sessionId);
        if ($attemptId !== null) {
            $reconcile->reconcile($attemptId, $sessionId);
        }
        $this->finish('processed');
    }

    private function attemptId(?string $reference, ?string $sessionId): ?string
    {
        if (is_string($reference) && Str::isUuid($reference) && $reference === strtolower($reference)
            && DB::table('payment_attempts')->where('id', $reference)->exists()) {
            return $reference;
        }
        if (is_string($sessionId) && $sessionId !== '') {
            $id = DB::table('payment_attempts')->where('provider_session_id', $sessionId)->value('id');
            if (is_string($id)) {
                return $id;
            }
        }

        return null;
    }

    private function finish(string $state): void
    {
        DB::table('provider_events')->where('id', $this->eventId)->where('processing_state', '!=', 'processed')->update([
            'processing_state' => $state,
            'processed_at' => $state === 'processed' ? now() : null,
            'next_attempt_at' => $state === 'processed' ? null : now()->addMinutes(1),
        ]);
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
