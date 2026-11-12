<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Platform\Support\MediaStoragePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

final class PruneExpiredExports
{
    public function prune(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000 || DB::transactionLevel() !== 0) {
            throw new InvalidArgumentException('Export cleanup requires a bounded standalone batch.');
        }
        app(MediaStoragePolicy::class)->assertPrivate();
        $ids = DB::table('export_jobs')->where('expires_at', '<=', now())
            ->where('status', '!=', 'expired')
            ->orderBy('expires_at')
            ->limit($limit)
            ->pluck('id');
        $changed = 0;
        foreach ($ids as $id) {
            $changed += DB::transaction(function () use ($id): int {
                $job = DB::table('export_jobs')->where('id', $id)->lockForUpdate()->first();
                if ($job === null || (string) $job->status === 'expired' || now()->lt($job->expires_at)) {
                    return 0;
                }
                $key = is_string($job->storage_key) ? $job->storage_key : '';
                if ($key !== '' && preg_match('#^exports/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.csv$#D', $key) === 1
                    && Storage::disk('local')->exists($key)
                    && ! Storage::disk('local')->delete($key)) {
                    throw new RuntimeException('Expired export cleanup failed.');
                }
                DB::table('export_jobs')->where('id', $id)->update([
                    'status' => 'expired',
                    'storage_key' => null,
                    'updated_at' => now(),
                ]);

                return 1;
            });
        }

        return $changed;
    }
}
