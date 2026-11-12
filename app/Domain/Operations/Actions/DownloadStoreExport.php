<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Platform\Support\MediaStoragePolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class DownloadStoreExport
{
    /**
     * @return array{filename: string, csv: string, headers: array<string, string>}
     */
    public function download(StaffAccount $actor, string $exportId, string $token): array
    {
        if (! $actor->hasPermission('exports.read')) {
            throw new AuthorizationException;
        }
        abort_unless(Str::isUuid($exportId) && $exportId === strtolower($exportId) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1, 404);
        app(MediaStoragePolicy::class)->assertPrivate();
        $digest = hash('sha256', $token);
        $job = DB::table('export_jobs')->where('id', $exportId)->first();
        abort_unless($job !== null && hash_equals((string) $job->staff_id, $actor->id), 404);
        abort_unless(hash_equals((string) $job->token_digest, $digest), 404);
        abort_unless((string) $job->status === 'ready' && now()->lt($job->expires_at), 404);
        $key = is_string($job->storage_key) ? $job->storage_key : '';
        abort_unless(preg_match('#^exports/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.csv$#D', $key) === 1, 404);
        abort_unless(Storage::disk('local')->exists($key), 404);
        $csv = Storage::disk('local')->get($key);
        abort_unless(is_string($csv), 404);
        DB::table('export_jobs')->where('id', $exportId)->whereNull('downloaded_at')->update([
            'downloaded_at' => now(),
            'updated_at' => now(),
        ]);
        app(RecordEvent::class)->record('platform.audit.recorded', 'audit:export-downloaded:'.$exportId, [
            'action' => 'operations.export.downloaded',
            'subject_type' => 'export_job',
            'subject_id' => $exportId,
            'actor_type' => 'staff',
            'actor_id' => $actor->id,
            'metadata' => ['version' => 1, 'reason_code' => (string) $job->dataset],
        ]);

        return [
            'filename' => (string) $job->dataset.'-export.csv',
            'csv' => $csv,
            'headers' => [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        ];
    }
}
