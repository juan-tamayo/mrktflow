<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\AiAuthoring\Actions\DispatchAuthoringRun;
use App\Domain\AiAuthoring\Contracts\AiAuthoringProvider;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class GenerateAuthoringDraft implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('default');
    }

    public function handle(DispatchAuthoringRun $runs, AiAuthoringProvider $provider): void
    {
        $runs->process($this->runId, $provider);
    }

    public function failed(?Throwable $exception): void
    {
        app(DispatchAuthoringRun::class)->abandon($this->runId);
    }
}
