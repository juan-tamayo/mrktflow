<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Operations\Actions\DeliverNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendNotification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public readonly string $deliveryId) {}

    public function handle(DeliverNotification $sender): void
    {
        $sender->deliver($this->deliveryId);
    }
}
