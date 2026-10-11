<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Jobs\ProcessMediaImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class QueueMedia
{
    public function safelyPublish(string $id): void
    {
        try {
            $this->publish($id);
        } catch (Throwable $exception) {
            Log::warning('media.publication_failed', ['media_id' => $id, 'error_code' => 'publication_failed', 'exception_class' => $exception::class]);
        }
    }

    public function publish(string $id): int
    {
        $connection = config()->array('queue.connections.database-outbox');
        if ($connection['driver'] !== 'database' || $connection['connection'] !== 'mysql'
            || $connection['after_commit'] !== false || config('database.default') !== 'mysql') {
            throw new LogicException('Media publication requires the transactional MySQL queue connection.');
        }

        return DB::transaction(function () use ($id): int {
            $asset = DB::table('media_assets')->where('id', $id)->lockForUpdate()->first();
            if ($asset === null || $asset->kind !== 'public_image' || $asset->status !== 'pending'
                || ($asset->queued_until !== null && now()->lt($asset->queued_until))) {
                return 0;
            }
            Queue::connection('database-outbox')->push(new ProcessMediaImage($id), '', 'media');
            DB::table('media_assets')->where('id', $id)->update(['queued_until' => now()->addSeconds(90)]);

            return 1;
        });
    }

    public function recover(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Media recovery batch must contain between 1 and 1000 assets.');
        }
        $ids = DB::table('media_assets')->where('kind', 'public_image')->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('queued_until')->orWhere('queued_until', '<=', now()))
            ->orderBy('created_at')->limit($limit)->pluck('id');
        $published = 0;
        foreach ($ids as $id) {
            $published += $this->publish($id);
        }

        return $published;
    }
}
