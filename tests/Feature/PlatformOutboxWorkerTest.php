<?php

declare(strict_types=1);

use App\Domain\Platform\Actions\PublishOutbox;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

it('PlatformOutboxWorker publishes committed intent automatically and leaves failed publication recoverable', function (): void {
    $id = app(RecordEvent::class)->record('platform.audit.recorded', 'audit:commit:1', ['action' => 'fixture.saved', 'subject_type' => 'fixture', 'subject_id' => '1']);
    expect(DB::table('jobs')->count())->toBe(1);
    expect(DB::table('consumer_receipts')->where('event_id', $id)->value('queued_until'))->not->toBeNull();
    config(['queue.connections.database-outbox.table' => 'missing_fixture_queue']);
    Queue::swap(new QueueManager(app()));
    $recoverable = app(RecordEvent::class)->record('platform.audit.recorded', 'audit:commit:2', ['action' => 'fixture.saved', 'subject_type' => 'fixture', 'subject_id' => '2']);
    expect(DB::table('outbox_events')->where('id', $recoverable)->exists())->toBeTrue();
    expect(DB::table('consumer_receipts')->where('event_id', $recoverable)->value('queued_until'))->toBeNull();
});

it('PlatformOutboxWorker recovers a killed transaction and applies its effect once after restart', function (): void {
    require_once base_path('tests/Fixtures/CrashableOutboxConsumer.php');
    config(['outbox.consumers' => array_replace(config('outbox.consumers'), ['fixture.worker' => ['fixture.worker' => CrashableOutboxConsumer::class]])]);
    $requested = base_path('.runtime/outbox-worker-pause-request');
    $paused = base_path('.runtime/outbox-worker-paused');
    @unlink($paused);
    file_put_contents($requested, 'test-only');
    $worker = new Process([base_path('bin/php'), base_path('tests/Fixtures/outbox-worker.php'), 'queue:work', 'database-outbox', '--env=testing', '--queue=outbox', '--once', '--timeout=60', '--tries=1'], base_path(), ['APP_ENV' => 'testing']);
    $worker->setTimeout(15);
    try {
        $id = app(RecordEvent::class)->record('fixture.worker', 'fixture:worker-crash:1', []);
        app(PublishOutbox::class)->forEvent($id);
        $worker->start();
        $deadline = microtime(true) + 8;
        while (! file_exists($paused) && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }
        expect(file_exists($paused))->toBeTrue();
        $worker->signal(SIGKILL);
        $worker->wait();
        expect(DB::table('audit_events')->where('id', $id)->count())->toBe(0);
        expect(DB::table('consumer_receipts')->where('event_id', $id)->value('status'))->toBe('pending');
        unlink($requested);
        // Move only the isolated fixture's reservation/lease past their real expiry.
        DB::table('jobs')->update(['reserved_at' => now()->subSeconds(91)->timestamp]);
        DB::table('consumer_receipts')->where('event_id', $id)->update(['queued_until' => now()->subSecond()]);
        expect(app(PublishOutbox::class)->recover())->toBe(1);
        $resumed = new Process([base_path('bin/php'), base_path('tests/Fixtures/outbox-worker.php'), 'queue:work', 'database-outbox', '--env=testing', '--queue=outbox', '--stop-when-empty', '--sleep=0', '--timeout=60', '--tries=1'], base_path(), ['APP_ENV' => 'testing']);
        $resumed->setTimeout(15);
        expect($resumed->run())->toBe(0);
        expect(DB::table('audit_events')->where('id', $id)->count())->toBe(1);
        expect(DB::table('consumer_receipts')->where('event_id', $id)->value('status'))->toBe('complete');
        expect(DB::table('jobs')->count())->toBe(0);
        expect(DB::table('failed_jobs')->count())->toBe(1);
    } finally {
        if ($worker->isRunning()) {
            $worker->stop(0, SIGKILL);
        }
        @unlink($requested);
        @unlink($paused);
    }
});
