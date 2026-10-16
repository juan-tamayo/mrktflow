<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Consumers;

use App\Domain\CheckoutOrders\Actions\RecordOrderEvent;
use App\Domain\Platform\Contracts\OutboxConsumer;
use App\Domain\Platform\Data\OutboxEvent;
use InvalidArgumentException;

final class AcknowledgeOrderCancelled implements OutboxConsumer
{
    public function handle(OutboxEvent $event): void
    {
        $payload = $event->payload;
        if (! is_string($payload['order_id'] ?? null) || ! is_string($payload['order_number'] ?? null)
            || ! is_string($payload['reason'] ?? null)) {
            throw new InvalidArgumentException('Invalid OrderCancelled payload.');
        }
        app(RecordOrderEvent::class)->record($payload['order_id'], 'cancelled', [
            'number' => $payload['order_number'],
            'reason_code' => 'cancelled',
        ], $event->id);
    }
}
