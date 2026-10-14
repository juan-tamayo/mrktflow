<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Consumers;

use App\Domain\Platform\Contracts\OutboxConsumer;
use App\Domain\Platform\Data\OutboxEvent;
use InvalidArgumentException;

final class AcknowledgeOrderPaid implements OutboxConsumer
{
    public function handle(OutboxEvent $event): void
    {
        $payload = $event->payload;
        if (! is_string($payload['order_id'] ?? null) || ! is_string($payload['attempt_id'] ?? null)
            || ! is_string($payload['capture_id'] ?? null) || ! is_int($payload['amount'] ?? null)
            || ! is_string($payload['currency'] ?? null)) {
            throw new InvalidArgumentException('Invalid OrderPaid payload.');
        }
    }
}
