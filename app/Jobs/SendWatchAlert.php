<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\ShoppingTools\Actions\DeliverWatchAlert;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendWatchAlert implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public readonly string $intentId) {}

    public function handle(DeliverWatchAlert $sender): void
    {
        $sender->deliver($this->intentId);
    }
}
