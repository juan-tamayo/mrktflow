<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Operations\Actions\BuildStoreExport;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ExportStoreData implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public readonly string $exportId)
    {
        $this->onQueue('default');
    }

    public function handle(BuildStoreExport $builder): void
    {
        $builder->build($this->exportId);
    }

    public function failed(?Throwable $exception): void
    {
        unset($exception);
        app(BuildStoreExport::class)->fail($this->exportId);
    }
}
