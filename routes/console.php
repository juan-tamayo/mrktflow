<?php

use App\Domain\Platform\Actions\InstallationPrerequisites;
use App\Domain\Platform\Actions\PlatformHealth;
use App\Domain\Platform\Actions\PublishOutbox;
use App\Domain\Platform\Actions\QueueMedia;
use App\Domain\Platform\Actions\RetryOutbox;
use App\Domain\Platform\Actions\SetupAccess;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('setup:secret', function (SetupAccess $access): int {
    app(InstallationPrerequisites::class)->assertReady();
    $secret = $access->issueSecret();
    $this->warn('Keep this installation secret private. Rotation revokes all previous setup access.');
    $this->line($secret);
    $this->info('This secret expires in 24 hours. Enter it in the setup screen; never place it in a URL.');

    return 0;
})->purpose('Issue or rotate the installation secret before first-owner completion');

Artisan::command('setup:check', function (InstallationPrerequisites $prerequisites): int {
    $failed = false;
    foreach ($prerequisites->report() as $check) {
        $this->line(($check['passed'] ? 'PASS' : ($check['required'] ? 'BLOCKED' : 'PENDING')).' '.$check['id'].': '.$check['message']);
        $failed = $failed || ($check['required'] && ! $check['passed']);
    }

    return $failed ? 1 : 0;
})->purpose('Check installation prerequisites without exposing secrets');

Artisan::command('outbox:recover', function (PublishOutbox $publisher): int {
    $this->info('Published '.$publisher->recover().' receipt(s).');

    return 0;
})->purpose('Recover bounded batches of due outbox work');

Artisan::command('platform:heartbeat', function (PlatformHealth $health): int {
    $health->heartbeat();

    return 0;
})->purpose('Record the scheduler heartbeat');

Artisan::command('platform:health', function (PlatformHealth $health): int {
    $report = $health->report();
    $this->line('scheduler: '.($report['scheduler_current'] ? 'current' : 'stale'));
    foreach (['outbox_pending', 'outbox_failed', 'queue_pending', 'queue_failed', 'oldest_due_seconds'] as $key) {
        $this->line($key.': '.($report[$key] ?? 'none'));
    }

    return $report['healthy'] ? 0 : 1;
})->purpose('Read private queue and scheduler diagnostics without payloads');

Schedule::command('platform:heartbeat')->everyMinute();
Schedule::command('outbox:recover')->everyMinute()->withoutOverlapping(2);

Artisan::command('outbox:retry {event} {consumer}', function (RetryOutbox $retry, PublishOutbox $publisher): int {
    $eventId = $this->argument('event');
    $consumerId = $this->argument('consumer');
    if (! is_string($eventId) || ! Str::isUuid($eventId)
        || ! is_string($consumerId) || ! preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $consumerId)
        || ! $retry->retry($eventId, $consumerId)) {
        $this->error('Only an existing quarantined receipt can be retried.');

        return 1;
    }
    $publisher->forEvent($eventId);
    $this->info('Receipt reset for recovery.');

    return 0;
})->purpose('Explicitly retry one quarantined outbox receipt after repairing its cause');

Artisan::command('outbox:failed', function (): int {
    $receipts = DB::table('consumer_receipts')->where('status', 'failed')
        ->orderBy('id')->limit(50)->get(['event_id', 'consumer', 'attempts', 'last_error_code']);
    foreach ($receipts as $receipt) {
        $this->line($receipt->event_id.' '.$receipt->consumer.' '.$receipt->attempts.' '.$receipt->last_error_code);
    }
    $this->info('Showing up to 50 quarantined receipts. No payloads included.');

    return 0;
})->purpose('List bounded quarantined receipt identifiers for operator recovery');

Artisan::command('media:recover', function (QueueMedia $queue): int {
    $this->info('Published '.$queue->recover().' media job(s).');

    return 0;
})->purpose('Recover bounded batches of pending image processing');

Schedule::command('media:recover')->everyMinute()->withoutOverlapping(2);
