<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\CustomerMailConfiguration;
use App\Domain\Operations\Support\DiskUsage;
use App\Domain\Operations\Support\RedactJobError;
use App\Domain\Operations\Support\RequireOwner;
use App\Domain\Platform\Support\PlatformVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class InspectHealth
{
    public function __construct(
        private readonly DiskUsage $disk,
        private readonly RedactJobError $redact,
        private readonly RequireOwner $owners,
        private readonly CustomerMailConfiguration $mail,
    ) {}

    /**
     * @return array{
     *     version: string,
     *     generated_at: string,
     *     thresholds: array{scheduler_stale_seconds: int, worker_lag_seconds: int, unresolved_payment_seconds: int, backup_max_age_seconds: int, disk_warn_percent: int},
     *     scheduler: array{current: bool, lag_seconds: int|null, warning: bool},
     *     worker: array{queue_pending: int, outbox_pending: int, oldest_due_seconds: int|null, warning: bool},
     *     payments: array{unknown_count: int, oldest_unknown_seconds: int|null, warning: bool},
     *     jobs: array{failed: int, errors: list<array{uuid: string, queue: string, error_code: string, failed_at: string|null}>, warning: bool},
     *     email: array{ready: bool, failed_deliveries: int, warning: bool},
     *     disk: array{path: string, free_bytes: int, total_bytes: int, used_percent: int, warning: bool},
     *     storage: array{private_writable: bool, public_writable: bool, warning: bool},
     *     backup: array{observed_at: string|null, age_seconds: int|null, warning: bool},
     *     warnings: list<array{code: string, message: string}>
     * }
     */
    public function forOwner(StaffAccount $actor): array
    {
        $this->owners->assert($actor);

        return $this->snapshot();
    }

    /**
     * @return array{
     *     version: string,
     *     generated_at: string,
     *     thresholds: array{scheduler_stale_seconds: int, worker_lag_seconds: int, unresolved_payment_seconds: int, backup_max_age_seconds: int, disk_warn_percent: int},
     *     scheduler: array{current: bool, lag_seconds: int|null, warning: bool},
     *     worker: array{queue_pending: int, outbox_pending: int, oldest_due_seconds: int|null, warning: bool},
     *     payments: array{unknown_count: int, oldest_unknown_seconds: int|null, warning: bool},
     *     jobs: array{failed: int, errors: list<array{uuid: string, queue: string, error_code: string, failed_at: string|null}>, warning: bool},
     *     email: array{ready: bool, failed_deliveries: int, warning: bool},
     *     disk: array{path: string, free_bytes: int, total_bytes: int, used_percent: int, warning: bool},
     *     storage: array{private_writable: bool, public_writable: bool, warning: bool},
     *     backup: array{observed_at: string|null, age_seconds: int|null, warning: bool},
     *     warnings: list<array{code: string, message: string}>
     * }
     */
    public function snapshot(): array
    {
        $thresholds = [
            'scheduler_stale_seconds' => max(1, (int) config('operations.health.scheduler_stale_seconds', 180)),
            'worker_lag_seconds' => max(1, (int) config('operations.health.worker_lag_seconds', 300)),
            'unresolved_payment_seconds' => max(1, (int) config('operations.health.unresolved_payment_seconds', 600)),
            'backup_max_age_seconds' => max(1, (int) config('operations.health.backup_max_age_seconds', 86400)),
            'disk_warn_percent' => max(1, min(100, (int) config('operations.health.disk_warn_percent', 90))),
        ];
        $path = config('operations.health.disk_path');
        $diskPath = is_string($path) && $path !== '' ? $path : storage_path();
        $disk = $this->disk->measure($diskPath);
        $schedulerObserved = DB::table('platform_heartbeats')->where('name', 'scheduler')->value('observed_at');
        $schedulerLag = $this->ageSeconds($schedulerObserved);
        $schedulerWarning = $schedulerLag === null || $schedulerLag > $thresholds['scheduler_stale_seconds'];
        $queuePending = (int) DB::table('jobs')->count();
        $outboxPending = (int) DB::table('consumer_receipts')->where('status', 'pending')->count();
        $oldestJob = DB::table('jobs')->where('available_at', '<=', now()->getTimestamp())->min('available_at');
        $jobAge = is_numeric($oldestJob) ? max(0, now()->getTimestamp() - (int) $oldestJob) : null;
        $oldestReceipt = DB::table('consumer_receipts')->where('status', 'pending')->where('available_at', '<=', now())->min('available_at');
        $receiptAge = $this->ageSeconds($oldestReceipt);
        $oldestDue = $this->oldest($jobAge, $receiptAge);
        $workerWarning = $oldestDue !== null && $oldestDue >= $thresholds['worker_lag_seconds'];
        $unknownCount = (int) DB::table('payment_attempts')->where('status', 'unknown')->count();
        $oldestUnknown = DB::table('payment_attempts')->where('status', 'unknown')->min('updated_at');
        $unknownAge = $this->ageSeconds($oldestUnknown);
        $paymentsWarning = $unknownAge !== null && $unknownAge >= $thresholds['unresolved_payment_seconds'];
        $failedRows = DB::table('failed_jobs')->orderByDesc('failed_at')->orderByDesc('id')->limit(50)->get();
        $errors = [];
        foreach ($failedRows as $row) {
            $uuid = is_string($row->uuid ?? null) ? $row->uuid : '';
            $queue = is_string($row->queue ?? null) ? $row->queue : '';
            $exception = is_string($row->exception ?? null) ? $row->exception : null;
            $failedAt = is_string($row->failed_at ?? null) ? $row->failed_at : null;
            $errors[] = [
                'uuid' => $uuid,
                'queue' => $queue,
                'error_code' => $this->redact->code($exception),
                'failed_at' => $failedAt,
            ];
        }
        $failedCount = (int) DB::table('failed_jobs')->count();
        $jobsWarning = $failedCount > 0;
        $mailReady = $this->mail->ready() !== null;
        $failedDeliveries = (int) DB::table('notification_deliveries')->where('status', 'failed')->count();
        $emailWarning = ! $mailReady || $failedDeliveries > 0;
        $diskWarning = $disk['used_percent'] >= $thresholds['disk_warn_percent'];
        $privateWritable = $this->writable('local');
        $publicWritable = $this->writable('public');
        $storageWarning = ! $privateWritable || ! $publicWritable;
        $backupObserved = DB::table('platform_heartbeats')->where('name', 'backup')->value('observed_at');
        $backupAge = $this->ageSeconds($backupObserved);
        $backupWarning = $backupAge === null || $backupAge > $thresholds['backup_max_age_seconds'];
        $warnings = [];
        if ($schedulerWarning) {
            $warnings[] = [
                'code' => 'scheduler_stale',
                'message' => 'Scheduler heartbeat is stale. Restart schedule:run. Age '.($schedulerLag === null ? 'missing' : $schedulerLag.'s').' (threshold '.$thresholds['scheduler_stale_seconds'].'s).',
            ];
        }
        if ($workerWarning) {
            $warnings[] = [
                'code' => 'worker_lag',
                'message' => 'Queue worker lag: oldest due work is '.$oldestDue.'s. Restart database workers (threshold '.$thresholds['worker_lag_seconds'].'s).',
            ];
        }
        if ($paymentsWarning) {
            $warnings[] = [
                'code' => 'unknown_payments',
                'message' => 'Unresolved unknown payment attempts older than '.$thresholds['unresolved_payment_seconds'].'s. Run payments:reconcile. Count '.$unknownCount.'.',
            ];
        }
        if ($jobsWarning) {
            $warnings[] = [
                'code' => 'failed_jobs',
                'message' => 'Failed jobs are listed with redacted error codes only. Count '.$failedCount.'.',
            ];
        }
        if ($emailWarning) {
            $warnings[] = [
                'code' => 'email_not_ready',
                'message' => $mailReady
                    ? 'Notification deliveries have failed. Review /admin/notifications.'
                    : 'Email transport is not ready. Configure a reviewed SMTP sender before opening the store.',
            ];
        }
        if ($diskWarning) {
            $warnings[] = [
                'code' => 'disk_full',
                'message' => 'Disk usage is '.$disk['used_percent'].'% (warn at '.$thresholds['disk_warn_percent'].'%). Free space on '.$disk['path'].'.',
            ];
        }
        if ($storageWarning) {
            $warnings[] = [
                'code' => 'storage_unwritable',
                'message' => 'Private or public storage is not writable. Check disk mounts and permissions.',
            ];
        }
        if ($backupWarning) {
            $warnings[] = [
                'code' => 'backup_stale',
                'message' => 'Backup observation is older than the '.$thresholds['backup_max_age_seconds'].'s policy. Record operations:backup-observed after a successful backup.',
            ];
        }

        return [
            'version' => PlatformVersion::CORE_VERSION,
            'generated_at' => now()->toIso8601String(),
            'thresholds' => $thresholds,
            'scheduler' => [
                'current' => ! $schedulerWarning,
                'lag_seconds' => $schedulerLag,
                'warning' => $schedulerWarning,
            ],
            'worker' => [
                'queue_pending' => $queuePending,
                'outbox_pending' => $outboxPending,
                'oldest_due_seconds' => $oldestDue,
                'warning' => $workerWarning,
            ],
            'payments' => [
                'unknown_count' => $unknownCount,
                'oldest_unknown_seconds' => $unknownAge,
                'warning' => $paymentsWarning,
            ],
            'jobs' => [
                'failed' => $failedCount,
                'errors' => $errors,
                'warning' => $jobsWarning,
            ],
            'email' => [
                'ready' => $mailReady,
                'failed_deliveries' => $failedDeliveries,
                'warning' => $emailWarning,
            ],
            'disk' => $disk + ['warning' => $diskWarning],
            'storage' => [
                'private_writable' => $privateWritable,
                'public_writable' => $publicWritable,
                'warning' => $storageWarning,
            ],
            'backup' => [
                'observed_at' => is_string($backupObserved) ? $backupObserved : null,
                'age_seconds' => $backupAge,
                'warning' => $backupWarning,
            ],
            'warnings' => $warnings,
        ];
    }

    private function ageSeconds(mixed $observed): ?int
    {
        if (! is_string($observed) || $observed === '') {
            return null;
        }

        return max(0, (int) Carbon::parse($observed)->diffInSeconds(now()));
    }

    private function oldest(?int $first, ?int $second): ?int
    {
        if ($first === null) {
            return $second;
        }
        if ($second === null) {
            return $first;
        }

        return max($first, $second);
    }

    private function writable(string $disk): bool
    {
        $root = config('filesystems.disks.'.$disk.'.root');

        return is_string($root) && is_dir($root) && is_writable($root);
    }
}
