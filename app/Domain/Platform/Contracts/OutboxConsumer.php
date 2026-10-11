<?php

declare(strict_types=1);

namespace App\Domain\Platform\Contracts;

use App\Domain\Platform\Data\OutboxEvent;

interface OutboxConsumer
{
    // Local database changes only. External delivery must use its own durable,
    // idempotent delivery intent, never network I/O inside this transaction.
    public function handle(OutboxEvent $event): void;
}
