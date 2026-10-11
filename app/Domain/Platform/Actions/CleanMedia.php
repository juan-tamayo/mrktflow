<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Support\MediaStoragePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

final class CleanMedia
{
    public function clean(int $limit = 100, int $fileLimit = 500): int
    {
        if ($limit < 1 || $limit > 1000 || $fileLimit < 1 || $fileLimit > 5000 || DB::transactionLevel() !== 0) {
            throw new InvalidArgumentException('Cleanup requires a bounded standalone batch.');
        }
        app(MediaStoragePolicy::class)->assertPublic();
        $cutoff = now()->subHours(24);
        $ids = DB::table('media_assets')->where('created_at', '<=', $cutoff)
            ->where(fn ($query) => $query->whereNull('cleanup_checked_at')->orWhere('cleanup_checked_at', '<=', $cutoff))
            ->orderBy('created_at')->limit($limit)->pluck('id');
        $changed = 0;
        $failures = 0;
        foreach ($ids as $id) {
            if ($fileLimit <= 0) {
                break;
            }
            if (! Str::isUuid($id)) {
                throw new LogicException('Invalid media identity during cleanup.');
            }
            try {
                $changed += DB::transaction(function () use ($id, $cutoff, &$fileLimit): int {
                    $asset = DB::table('media_assets')->where('id', $id)->lockForUpdate()->first();
                    if ($asset === null || ($asset->cleanup_checked_at !== null && now()->subHours(24)->lt($asset->cleanup_checked_at))) {
                        return 0;
                    }
                    $prefix = 'media/'.$id.'/';
                    if ($asset->original_key !== $prefix.'original') {
                        throw new LogicException('Invalid media storage key during cleanup.');
                    }
                    $public = Storage::disk('public');
                    $changedFiles = 0;
                    foreach ($public->getDriver()->listContents(rtrim($prefix, '/'), false) as $entry) {
                        if ($fileLimit <= 0) {
                            return $changedFiles > 0 ? 1 : 0;
                        }
                        $fileLimit--;
                        $path = $entry->path();
                        $name = substr($path, strlen($prefix));
                        if (! $entry->isFile() || ! str_starts_with($path, $prefix)
                            || ! preg_match('/^(?:[a-f0-9]{64}\.png|[a-f0-9-]{36}\.tmp)$/D', $name)
                            || $path === $asset->public_key || $public->lastModified($path) > $cutoff->timestamp) {
                            continue;
                        }
                        if (! $public->delete($path)) {
                            throw new RuntimeException('Generated media cleanup failed.');
                        }
                        $changedFiles++;
                    }
                    if ($asset->purged_at === null && in_array($asset->status, ['staging', 'failed'], true)
                        && now()->subHours(24)->gte($asset->updated_at)) {
                        if ($fileLimit <= 0) {
                            return $changedFiles > 0 ? 1 : 0;
                        }
                        $fileLimit--;
                        if (! Storage::disk('local')->delete($asset->original_key)) {
                            throw new RuntimeException('Private draft cleanup failed.');
                        }
                        DB::table('media_assets')->where('id', $id)->update([
                            'status' => 'failed', 'error_code' => $asset->error_code ?? 'upload_abandoned',
                            'purged_at' => now(), 'queued_until' => null, 'updated_at' => now(),
                        ]);
                        app(RecordEvent::class)->record('platform.audit.recorded', 'media:purged:'.$id, [
                            'action' => 'media.draft_purged', 'subject_type' => 'media', 'subject_id' => $id,
                        ]);
                        $changedFiles++;
                    }
                    DB::table('media_assets')->where('id', $id)->update(['cleanup_checked_at' => now()]);

                    return $changedFiles > 0 ? 1 : 0;
                });
            } catch (Throwable $exception) {
                $failures++;
                Log::warning('media.cleanup_failed', ['media_id' => $id, 'error_code' => 'cleanup_failed', 'exception_class' => $exception::class]);
            }
        }

        if ($failures > 0) {
            throw new RuntimeException('Media cleanup was incomplete.');
        }

        return $changed;
    }
}
