<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Consumers;

use App\Domain\Fulfillment\Actions\IssueEntitlements;
use App\Domain\Platform\Contracts\OutboxConsumer;
use App\Domain\Platform\Data\OutboxEvent;
use InvalidArgumentException;

/**
 * Independent paid-event consumer for digital entitlements (FUL-04).
 * Runs alongside the receipt consumer; each keeps its own receipt, so
 * redelivery of either never duplicates the other's work.
 */
final class IssueOrderEntitlements implements OutboxConsumer
{
    public function handle(OutboxEvent $event): void
    {
        $payload = $event->payload;
        if (! is_string($payload['order_id'] ?? null) || ! is_string($payload['attempt_id'] ?? null)
            || ! is_string($payload['capture_id'] ?? null) || ! is_int($payload['amount'] ?? null)
            || ! is_string($payload['currency'] ?? null) || ! is_string($payload['order_number'] ?? null)) {
            throw new InvalidArgumentException('Invalid OrderPaid payload.');
        }
        app(IssueEntitlements::class)->issueFromPaidEvent($event);
    }
}
