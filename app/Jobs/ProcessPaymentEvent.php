<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
        $event = DB::table('provider_events')->where('id', $this->eventId)->first();
        if ($event === null) {
            throw new RuntimeException('Missing provider event.');
        }
    }
}
