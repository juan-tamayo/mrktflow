<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RecordOrderEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $orderId, string $type, array $payload, ?string $sourceEventId = null): string
    {
        if (! Str::isUuid($orderId) || $orderId !== strtolower($orderId)
            || ! in_array($type, ['paid', 'cancelled', 'receipt_queued', 'receipt_resent'], true)
            || ($sourceEventId !== null && (! Str::isUuid($sourceEventId) || $sourceEventId !== strtolower($sourceEventId)))) {
            throw new InvalidArgumentException('Invalid order event.');
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $now = now();

        try {
            $id = (string) Str::orderedUuid();
            DB::table('order_events')->insert([
                'id' => $id,
                'order_id' => $orderId,
                'type' => $type,
                'source_event_id' => $sourceEventId,
                'payload' => $json,
                'created_at' => $now,
            ]);

            return $id;
        } catch (UniqueConstraintViolationException) {
            $existing = DB::table('order_events')->where('order_id', $orderId)->where('type', $type)
                ->where('source_event_id', $sourceEventId)->value('id');
            if (! is_string($existing) || $existing === '') {
                throw new InvalidArgumentException('Order event could not be recorded.');
            }

            return $existing;
        }
    }
}
