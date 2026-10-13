<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Catalog\Actions\PreviewProductImport;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

final class ValidateProductImport implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public readonly string $importId, public readonly string $staffId) {}

    public function handle(PreviewProductImport $preview): void
    {
        $staff = StaffAccount::query()->find($this->staffId);
        abort_unless($staff instanceof StaffAccount, 404);
        $report = $preview->preview($staff, ['import_id' => $this->importId]);
        Storage::disk('local')->put(
            'catalog-imports/'.$this->importId.'/report.json',
            json_encode($report, JSON_THROW_ON_ERROR),
        );
    }
}
