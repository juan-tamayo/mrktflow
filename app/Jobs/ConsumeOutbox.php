<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Platform\Actions\ConsumeEvent;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ConsumeOutbox implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public readonly string $eventId, public readonly string $consumerId) {}

    public function handle(ConsumeEvent $consumer): void
    {
        $consumer->consume($this->eventId, $this->consumerId);
    }
}
