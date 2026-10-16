<?php

use App\Domain\Identity\Actions\QueueCustomerChallengeMail;
use App\Domain\Platform\Actions\CleanMedia;
use App\Domain\Platform\Actions\InstallationPrerequisites;
use App\Domain\Platform\Actions\PlatformHealth;
use App\Domain\Platform\Actions\PublishOutbox;
use App\Domain\Platform\Actions\QueueMedia;
use App\Domain\Platform\Actions\RetryOutbox;
use App\Domain\Platform\Actions\SetupAccess;
use App\Jobs\ExpireCheckoutSessions;
use App\Jobs\ReconcilePendingPayments;
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
    foreach (['outbox_pending', 'outbox_failed', 'queue_pending', 'queue_failed', 'oldest_due_seconds', 'media_pending', 'media_failed', 'media_oldest_pending_seconds'] as $key) {
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

Artisan::command('media:clean', function (CleanMedia $cleanup): int {
    $this->info('Cleaned '.$cleanup->clean().' media asset(s).');

    return 0;
})->purpose('Clean abandoned media drafts and old generated orphans with bounded work');

Schedule::command('media:clean')->everyMinute()->withoutOverlapping(2);

Artisan::command('identity:mail-recover', function (QueueCustomerChallengeMail $queue): int {
    $this->info('Published '.$queue->recover().' customer credential email job(s).');

    return 0;
})->purpose('Recover bounded customer credential-mail work and purge expired encrypted payloads');

Schedule::command('identity:mail-recover')->everyMinute()->withoutOverlapping(2);

Artisan::command('payments:reconcile', function (): int {
    (new ReconcilePendingPayments)->handle();
    $this->info('Reconciled a bounded batch of unknown payment attempts.');

    return 0;
})->purpose('Query unknown payment attempts and apply verified provider outcomes');

Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping(2);

Artisan::command('payments:expire', function (): int {
    (new ExpireCheckoutSessions)->handle();
    $this->info('Expired a bounded batch of due checkout sessions.');

    return 0;
})->purpose('Cancel due provider sessions and release only terminal unpaid holds');

Schedule::command('payments:expire')->everyMinute()->withoutOverlapping(2);

Artisan::command('identity:mail-health', function (): int {
    $pending = DB::table('customer_challenge_deliveries')->whereIn('status', ['pending', 'sending'])->count();
    $failed = DB::table('customer_challenge_deliveries')->where('status', 'failed')->count();
    $blocked = DB::table('customer_challenge_deliveries')->where('status', 'pending')->where('last_error_code', 'mail_configuration_unavailable')->count();
    $this->line('pending: '.$pending);
    $this->line('failed: '.$failed);
    $this->line('configuration_blocked: '.$blocked);

    return $failed === 0 && $blocked === 0 ? 0 : 1;
})->purpose('Read private customer email counts without recipient or credential data');

Artisan::command('identity:mail-retry {delivery}', function (QueueCustomerChallengeMail $queue): int {
    $id = $this->argument('delivery');
    if (! is_string($id) || ! Str::isUuid($id)) {
        $this->error('Provide a delivery UUID.');

        return 1;
    }
    $reset = DB::transaction(function () use ($id): bool {
        $row = DB::table('customer_challenge_deliveries')->where('id', $id)->lockForUpdate()->first();
        if ($row === null || $row->status !== 'failed' || $row->payload === null || now()->gte($row->expires_at)) {
            return false;
        }
        DB::table('customer_challenge_deliveries')->where('id', $id)->update([
            'status' => 'pending', 'attempts' => 0, 'claim_id' => null, 'lease_until' => null, 'queued_until' => null,
            'last_error_code' => null, 'available_at' => now(), 'updated_at' => now(),
        ]);

        return true;
    });
    if (! $reset) {
        $this->error('Only an unexpired failed delivery can be retried.');

        return 1;
    }
    $queue->safelyPublish($id);
    $this->info('Delivery scheduled for recovery; current challenge eligibility is checked before sending.');

    return 0;
})->purpose('Explicitly retry a failed customer email after repairing its cause');
