<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PlatformHealth
{
    public function heartbeat(): void
    {
        DB::table('platform_heartbeats')->upsert([
            ['name' => 'scheduler', 'observed_at' => now()],
        ], ['name'], ['observed_at']);
    }

    /** @return array{scheduler_current: bool, outbox_pending: int, outbox_failed: int, queue_pending: int, queue_failed: int, oldest_due_seconds: int|null, media_pending: int, media_failed: int, media_oldest_pending_seconds: int|null, healthy: bool} */
    public function report(): array
    {
        $observed = DB::table('platform_heartbeats')->where('name', 'scheduler')->value('observed_at');
        $current = is_string($observed) && Carbon::parse($observed)->between(now()->subSeconds(180), now()->addSeconds(5));
        $failed = DB::table('consumer_receipts')->where('status', 'failed')->count();
        $queueFailed = DB::table('failed_jobs')->count();
        $oldest = DB::table('consumer_receipts')->where('status', 'pending')->where('available_at', '<=', now())->min('available_at');
        $age = is_string($oldest) ? max(0, (int) Carbon::parse($oldest)->diffInSeconds(now())) : null;

        $mediaPending = DB::table('media_assets')->whereIn('status', ['staging', 'pending']);
        $mediaOldest = $mediaPending->min('created_at');
        $mediaAge = is_string($mediaOldest) ? max(0, (int) Carbon::parse($mediaOldest)->diffInSeconds(now())) : null;
        $mediaFailed = DB::table('media_assets')->where('status', 'failed')->whereNull('purged_at')->count();

        return [
            'scheduler_current' => $current,
            'outbox_pending' => DB::table('consumer_receipts')->where('status', 'pending')->count(),
            'outbox_failed' => $failed,
            'queue_pending' => DB::table('jobs')->count(),
            'queue_failed' => $queueFailed,
            'oldest_due_seconds' => $age,
            'media_pending' => $mediaPending->count(),
            'media_failed' => $mediaFailed,
            'media_oldest_pending_seconds' => $mediaAge,
            'healthy' => $current && $failed === 0 && $queueFailed === 0 && $mediaFailed === 0
                && ($age === null || $age <= 300) && ($mediaAge === null || $mediaAge <= 300),
        ];
    }
}
