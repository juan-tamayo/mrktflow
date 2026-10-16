<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\Platform\Data\OutboxEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class QueueOrderReceipt
{
    public function queueFromPaidEvent(OutboxEvent $event): void
    {
        $orderId = $event->payload['order_id'] ?? null;
        if (! is_string($orderId) || ! Str::isUuid($orderId)) {
            throw new InvalidArgumentException('Invalid paid order for receipt.');
        }
        $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        if ($order === null) {
            throw new InvalidArgumentException('Paid order is missing.');
        }
        $recipient = $this->string($order, 'email');
        $intentKey = 'receipt:paid:'.$orderId;
        $id = (string) Str::uuid();
        try {
            DB::table('order_receipt_intents')->insert([
                'id' => $id,
                'order_id' => $orderId,
                'source_event_id' => $event->id,
                'template' => 'order-receipt',
                'recipient' => $recipient,
                'intent_key' => $intentKey,
                'kind' => 'event',
                'status' => 'queued',
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }
        app(RecordOrderEvent::class)->record($orderId, 'receipt_queued', [
            'intent_id' => $id,
            'template' => 'order-receipt',
            'amount' => $this->int($order, 'grand_total_amount'),
            'currency' => $this->string($order, 'currency'),
            'exponent' => $this->int($order, 'exponent'),
            'number' => $this->string($order, 'number'),
        ], $event->id);
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
