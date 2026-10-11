<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Platform\Actions\ProcessMedia;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessMediaImage implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public readonly string $mediaId) {}

    public function handle(ProcessMedia $processor): void
    {
        $processor->process($this->mediaId);
    }
}
