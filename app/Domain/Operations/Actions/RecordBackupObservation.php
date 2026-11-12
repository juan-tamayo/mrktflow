<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use Illuminate\Support\Facades\DB;

final class RecordBackupObservation
{
    public function record(): void
    {
        DB::table('platform_heartbeats')->upsert([
            ['name' => 'backup', 'observed_at' => now()],
        ], ['name'], ['observed_at']);
    }
}
