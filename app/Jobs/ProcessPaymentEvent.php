<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\CheckoutOrders\Actions\ReconcileExternalAdjustment;
use App\Domain\CheckoutOrders\Actions\ReconcilePayment;
use App\Domain\CheckoutOrders\Data\DisputeObservation;
use App\Domain\CheckoutOrders\Data\ExternalRefundObservation;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
        $type = is_string($decoded['type'] ?? null) ? (string) $decoded['type'] : '';
        if ($type === 'refund.created' || $type === 'refund.updated') {
            $this->externalRefunds([$object], null);
            $this->finish('processed');

            return;
        }
        if ($type === 'charge.refunded') {
            $entries = array_values(is_array($object['refunds']['data'] ?? null) ? $object['refunds']['data'] : []);
            $fallback = is_string($object['payment_intent'] ?? null) ? (string) $object['payment_intent'] : null;
            $this->externalRefunds($entries, $fallback);
            $this->finish('processed');

            return;
        }
        if (str_starts_with($type, 'charge.dispute.')) {
            $this->externalDispute($type, $object);
            $this->finish('processed');

            return;
        }
        $sessionId = is_string($object['id'] ?? null) ? (string) $object['id'] : null;
        $reference = is_string($object['client_reference_id'] ?? null) ? (string) $object['client_reference_id'] : null;
        $attemptId = $this->attemptId($reference, $sessionId);
        if ($attemptId !== null) {
            $reconcile->reconcile($attemptId, $sessionId);
        }
        $this->finish('processed');
    }

    /** @param  list<mixed>  $entries */
    private function externalRefunds(array $entries, ?string $fallbackCapture): void
    {
        $adjust = app(ReconcileExternalAdjustment::class);
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            try {
                $observation = $this->refundObservation($entry, $fallbackCapture);
            } catch (InvalidArgumentException) {
                continue;
            }
            $adjust->reconcileRefund($observation, $this->eventId);
        }
    }

    /** @param  array<string, mixed>  $refund */
    private function refundObservation(array $refund, ?string $fallbackCapture): ExternalRefundObservation
    {
        $capture = $refund['payment_intent'] ?? $fallbackCapture;
        $meta = is_array($refund['metadata'] ?? null) ? $refund['metadata'] : [];
        $localId = $meta['refund_id'] ?? null;

        return new ExternalRefundObservation(
            is_string($refund['id'] ?? null) ? (string) $refund['id'] : '',
            is_string($capture) ? $capture : '',
            is_int($refund['amount'] ?? null) ? (int) $refund['amount'] : -1,
            is_string($refund['currency'] ?? null) ? strtoupper((string) $refund['currency']) : '',
            is_string($refund['status'] ?? null) ? (string) $refund['status'] : '',
            is_string($refund['reason'] ?? null) ? (string) $refund['reason'] : null,
            is_string($localId) ? $localId : null,
        );
    }

    /** @param  array<string, mixed>  $object */
    private function externalDispute(string $type, array $object): void
    {
        $rawStatus = is_string($object['status'] ?? null) ? (string) $object['status'] : '';
        $status = match (true) {
            $type === 'charge.dispute.closed' => $rawStatus,
            in_array($rawStatus, ['won', 'lost'], true) => $rawStatus,
            default => 'opened',
        };
        try {
            $observation = new DisputeObservation(
                is_string($object['id'] ?? null) ? (string) $object['id'] : '',
                is_string($object['payment_intent'] ?? null) ? (string) $object['payment_intent'] : '',
                is_int($object['amount'] ?? null) ? (int) $object['amount'] : -1,
                is_string($object['currency'] ?? null) ? strtoupper((string) $object['currency']) : '',
                $status,
                is_string($object['reason'] ?? null) ? (string) $object['reason'] : null,
            );
        } catch (InvalidArgumentException) {
            return;
        }
        app(ReconcileExternalAdjustment::class)->reconcileDispute($observation, $this->eventId);
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
