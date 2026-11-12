<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Identity\Actions\PruneExpiredData as PruneExpiredDataAction;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class PruneExpiredData implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(PruneExpiredDataAction $prune): void
    {
        $prune->prune();
    }
}
