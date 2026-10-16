<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Consumers;

use App\Domain\CheckoutOrders\Actions\QueueOrderReceipt;
use App\Domain\CheckoutOrders\Actions\RecordOrderEvent;
use App\Domain\Platform\Contracts\OutboxConsumer;
use App\Domain\Platform\Data\OutboxEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AcknowledgeOrderPaid implements OutboxConsumer
{
    public function handle(OutboxEvent $event): void
    {
        $payload = $event->payload;
        if (! is_string($payload['order_id'] ?? null) || ! is_string($payload['attempt_id'] ?? null)
            || ! is_string($payload['capture_id'] ?? null) || ! is_int($payload['amount'] ?? null)
            || ! is_string($payload['currency'] ?? null) || ! is_string($payload['order_number'] ?? null)) {
            throw new InvalidArgumentException('Invalid OrderPaid payload.');
        }
        $order = DB::table('orders')->where('id', $payload['order_id'])->lockForUpdate()->first();
        if ($order === null) {
            throw new InvalidArgumentException('Invalid OrderPaid payload.');
        }
        app(RecordOrderEvent::class)->record($payload['order_id'], 'paid', [
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'exponent' => is_numeric(get_object_vars($order)['exponent'] ?? null) ? (int) get_object_vars($order)['exponent'] : 2,
            'number' => $payload['order_number'],
        ], $event->id);
        app(QueueOrderReceipt::class)->queueFromPaidEvent($event);
    }
}
