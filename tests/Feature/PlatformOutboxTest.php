<?php

declare(strict_types=1);

use App\Domain\Platform\Actions\ConsumeEvent;
use App\Domain\Platform\Actions\PublishOutbox;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Platform\Contracts\OutboxConsumer;
use App\Domain\Platform\Data\OutboxEvent;
use App\Domain\Platform\Support\OutboundHttp;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

it('PlatformOutbox records immutable encrypted intent with durable consumer receipts', function (): void {
    $id = app(RecordEvent::class)->record('platform.audit.recorded', 'audit:fixture:1', ['action' => 'fixture.saved', 'subject_type' => 'fixture', 'subject_id' => '1']);
    $event = DB::table('outbox_events')->where('id', $id)->first();
    expect($event->payload)->not->toContain('fixture.saved');
    expect(DB::table('consumer_receipts')->where('event_id', $id)->count())->toBe(1);
    expect(DB::table('consumer_receipts')->where('event_id', $id)->value('status'))->toBe('pending');
});

it('PlatformOutbox replays the same intent and rejects changed payloads or event types', function (): void {
    $recorder = app(RecordEvent::class);
    $payload = ['action' => 'fixture.saved', 'subject_type' => 'fixture', 'subject_id' => '1'];
    $id = $recorder->record('platform.audit.recorded', 'audit:fixture:1', $payload);
    expect($recorder->record('platform.audit.recorded', 'audit:fixture:1', array_reverse($payload, true)))->toBe($id);
    expect(DB::table('outbox_events')->count())->toBe(1);
    expect(fn () => $recorder->record('platform.audit.recorded', 'audit:fixture:1', array_replace($payload, ['subject_id' => '2'])))
        ->toThrow(DomainException::class);
});

it('PlatformOutbox rolls back event intent with the business transaction', function (): void {
    try {
        DB::transaction(function (): void {
            DB::table('store_settings')->insert(['id' => 1, 'version' => 1, 'values' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:rollback:1', ['action' => 'fixture.saved', 'subject_type' => 'fixture', 'subject_id' => '1']);
            throw new RuntimeException('Simulated interruption before commit');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated interruption before commit');
        // Both the business change and event intent must be absent.
    }
    expect(DB::table('store_settings')->count())->toBe(0);
    expect(DB::table('outbox_events')->count())->toBe(0);
    expect(DB::table('consumer_receipts')->count())->toBe(0);
});

it('PlatformOutbox rejects unknown event bindings and unsafe payloads before storage', function (): void {
    expect(fn () => app(RecordEvent::class)->record('unknown.event', 'fixture:1', []))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(RecordEvent::class)->record('platform.audit.recorded', 'fixture:2', ['value' => new stdClass]))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(RecordEvent::class)->record('platform.audit.recorded', 'fixture:3', ['value' => str_repeat('x', 65537)]))->toThrow(InvalidArgumentException::class);
    expect(DB::table('outbox_events')->count())->toBe(0);
});

it('PlatformOutbox treats producer keys as exact case-sensitive identifiers', function (): void {
    $recorder = app(RecordEvent::class);
    $payload = ['action' => 'fixture.saved', 'subject_type' => 'fixture', 'subject_id' => '1'];
    $first = $recorder->record('platform.audit.recorded', 'fixture:Case', $payload);
    expect($recorder->record('platform.audit.recorded', 'fixture:case', $payload))->not->toBe($first);
    expect(DB::table('outbox_events')->count())->toBe(2);
});

it('PlatformOutbox commits one audit effect and ignores completed redelivery', function (): void {
    $id = app(RecordEvent::class)->record('platform.audit.recorded', 'audit:consume:1', ['action' => 'fixture.saved', 'subject_type' => 'fixture', 'subject_id' => '1']);
    $consumer = app(ConsumeEvent::class);
    $consumer->consume($id, 'platform.audit');
    $consumer->consume($id, 'platform.audit');
    expect(DB::table('audit_events')->where('id', $id)->count())->toBe(1);
    expect(DB::table('consumer_receipts')->where('event_id', $id)->first())
        ->status->toBe('complete')->attempts->toBe(1)->completed_at->not->toBeNull();
    expect(DB::table('outbox_events')->where('id', $id)->value('published_at'))->not->toBeNull();
});

it('PlatformOutbox rolls back failed consumer effects and retries only when due', function (): void {
    config(['outbox.consumers' => array_replace(config('outbox.consumers'), ['fixture.failure' => ['fixture.consumer' => OutboxRollbackFixture::class]])]);
    $id = app(RecordEvent::class)->record('fixture.failure', 'fixture:failure:1', []);
    $consumer = app(ConsumeEvent::class);
    $consumer->consume($id, 'fixture.consumer');
    expect(DB::table('store_settings')->count())->toBe(0);
    expect(DB::table('consumer_receipts')->where('event_id', $id)->first())
        ->status->toBe('pending')->attempts->toBe(1)->last_error_code->toBe('consumer_failed');
    $consumer->consume($id, 'fixture.consumer');
    expect(DB::table('consumer_receipts')->where('event_id', $id)->value('attempts'))->toBe(1);
    $this->travel(11)->seconds();
    $consumer->consume($id, 'fixture.consumer');
    expect(DB::table('consumer_receipts')->where('event_id', $id)->value('attempts'))->toBe(2);
    expect(DB::table('store_settings')->count())->toBe(0);
});

it('PlatformOutbox quarantines exhausted work without changing committed business data', function (): void {
    config(['outbox.consumers' => array_replace(config('outbox.consumers'), ['fixture.failure' => ['fixture.consumer' => OutboxRollbackFixture::class]])]);
    $id = app(RecordEvent::class)->record('fixture.failure', 'fixture:deadletter:1', []);
    for ($attempt = 0; $attempt < 6; $attempt++) {
        app(ConsumeEvent::class)->consume($id, 'fixture.consumer');
        $this->travel(1801)->seconds();
    }
    $receipt = DB::table('consumer_receipts')->where('event_id', $id)->first();
    expect($receipt)->status->toBe('failed')->attempts->toBe(6)->completed_at->toBeNull();
    $this->artisan('outbox:failed')->expectsOutputToContain($id.' fixture.consumer 6 consumer_failed')->assertSuccessful();
    app(ConsumeEvent::class)->consume($id, 'fixture.consumer');
    expect(DB::table('consumer_receipts')->where('event_id', $id)->value('attempts'))->toBe(6);
    expect(DB::table('outbox_events')->where('id', $id)->value('published_at'))->toBeNull();
});

it('PlatformOutbox refuses tampered ciphertext before applying local effects', function (): void {
    $id = app(RecordEvent::class)->record('platform.audit.recorded', 'audit:tampered:1', ['action' => 'fixture.saved', 'subject_type' => 'fixture', 'subject_id' => '1']);
    DB::table('outbox_events')->where('id', $id)->update(['payload' => 'corrupt']);
    app(ConsumeEvent::class)->consume($id, 'platform.audit');
    expect(DB::table('audit_events')->count())->toBe(0);
    expect(DB::table('consumer_receipts')->where('event_id', $id)->first())
        ->status->toBe('pending')->last_error_code->toBe('consumer_failed');
});

final class OutboxRollbackFixture implements OutboxConsumer
{
    public function handle(OutboxEvent $event): void
    {
        DB::table('store_settings')->insert(['id' => 1, 'version' => 1, 'values' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        throw new RuntimeException('Private fixture payload must not appear in diagnostics.');
    }
}

it('PlatformOutbox reports only safe identifiers for consumer failures', function (): void {
    config(['outbox.consumers' => array_replace(config('outbox.consumers'), ['fixture.failure' => ['fixture.consumer' => OutboxRollbackFixture::class]])]);
    $id = app(RecordEvent::class)->record('fixture.failure', 'fixture:diagnostics:1', []);
    $entries = [];
    Log::listen(function ($message) use (&$entries): void {
        $entries[] = [$message->message, $message->context];
    });
    app(ConsumeEvent::class)->consume($id, 'fixture.consumer');
    expect($entries)->toHaveCount(1);
    expect($entries[0][0])->toBe('outbox.consumer_failed');
    expect($entries[0][1]['event_id'])->toBe($id);
    expect(json_encode($entries))->not->toContain('Private fixture payload');
    expect(array_keys($entries[0][1]))->toBe(['event_id', 'consumer', 'attempt', 'correlation_id', 'error_code', 'exception_class', 'quarantined']);
});

it('PlatformOutbox publishes one encrypted job while the receipt lease is active', function (): void {
    $id = app(RecordEvent::class)->record('platform.audit.recorded', 'audit:publish:1', ['action' => 'fixture.saved', 'subject_type' => 'fixture', 'subject_id' => '1']);
    $publisher = app(PublishOutbox::class);
    $publisher->forEvent($id);
    $publisher->forEvent($id);
    expect(DB::table('jobs')->where('queue', 'outbox')->count())->toBe(1);
    expect(DB::table('jobs')->value('payload'))->not->toContain('fixture.saved');
    expect(DB::table('consumer_receipts')->where('event_id', $id)->value('queued_until'))->not->toBeNull();
});

it('PlatformOutbox recovers expired leases and does not enqueue completed receipts', function (): void {
    $id = app(RecordEvent::class)->record('platform.audit.recorded', 'audit:recover:1', ['action' => 'fixture.saved', 'subject_type' => 'fixture', 'subject_id' => '1']);
    $publisher = app(PublishOutbox::class);
    $publisher->recover();
    DB::table('jobs')->delete(); // A lost queue reservation must not erase intent.
    $this->travel(91)->seconds();
    expect($publisher->recover())->toBe(1);
    expect(DB::table('jobs')->count())->toBe(1);
    app(ConsumeEvent::class)->consume($id, 'platform.audit');
    DB::table('jobs')->delete();
    expect($publisher->recover())->toBe(0);
});

it('PlatformOutbox rolls back publication leases when the queue write fails', function (): void {
    config(['queue.connections.database-outbox.table' => 'missing_fixture_queue']);
    $id = app(RecordEvent::class)->record('platform.audit.recorded', 'audit:queue-failure:1', ['action' => 'fixture.saved', 'subject_type' => 'fixture', 'subject_id' => '1']);
    expect(fn () => app(PublishOutbox::class)->forEvent($id))
        ->toThrow(QueryException::class);
    expect(DB::table('consumer_receipts')->where('event_id', $id)->value('queued_until'))->toBeNull();
    expect(DB::table('outbox_events')->where('id', $id)->exists())->toBeTrue();
});

it('PlatformOutbox exposes private health counts and detects missing or stale scheduler heartbeats', function (): void {
    $this->artisan('platform:health')->expectsOutputToContain('scheduler: stale')->assertExitCode(1);
    $this->artisan('platform:heartbeat')->assertSuccessful();
    $this->artisan('platform:health')->expectsOutputToContain('scheduler: current')->assertSuccessful();
    $id = app(RecordEvent::class)->record('platform.audit.recorded', 'audit:health:1', ['action' => 'fixture.saved', 'subject_type' => 'fixture', 'subject_id' => '1']);
    DB::table('consumer_receipts')->where('event_id', $id)->update(['status' => 'failed']);
    $this->artisan('platform:health')->expectsOutputToContain('outbox_failed: 1')->assertExitCode(1);
    $this->travel(181)->seconds();
    $this->artisan('platform:health')->expectsOutputToContain('scheduler: stale')->assertExitCode(1);
});

it('PlatformOutbox runs bounded recovery from the scheduler command', function (): void {
    $id = app(RecordEvent::class)->record('platform.audit.recorded', 'audit:scheduler:1', ['action' => 'fixture.saved', 'subject_type' => 'fixture', 'subject_id' => '1']);
    DB::table('jobs')->delete();
    DB::table('consumer_receipts')->where('event_id', $id)->update(['queued_until' => null]);
    $this->artisan('outbox:recover')->expectsOutputToContain('Published 1 receipt(s).')->assertSuccessful();
    expect(DB::table('jobs')->count())->toBe(1);
});

it('PlatformOutbox bounds outbound HTTP calls and verifies TLS without following redirects', function (): void {
    $options = app(OutboundHttp::class)->client()->getOptions();
    expect($options['connect_timeout'])->toBe(5);
    expect($options['timeout'])->toBe(20);
    expect($options['verify'])->toBeTrue();
    expect($options['allow_redirects'])->toBeFalse();
});

it('PlatformOutbox explicitly retries quarantined receipts and refuses to reopen completed work', function (): void {
    $id = app(RecordEvent::class)->record('platform.audit.recorded', 'audit:manual-retry:1', ['action' => 'fixture.saved', 'subject_type' => 'fixture', 'subject_id' => '1']);
    DB::table('jobs')->delete();
    DB::table('consumer_receipts')->where('event_id', $id)->update(['status' => 'failed', 'attempts' => 6, 'queued_until' => null, 'last_error_code' => 'consumer_failed']);
    $this->artisan('outbox:retry', ['event' => $id, 'consumer' => 'platform.audit'])->assertSuccessful();
    expect(DB::table('consumer_receipts')->where('event_id', $id)->first())->status->toBe('pending')->attempts->toBe(0);
    expect(DB::table('jobs')->count())->toBe(1);
    app(ConsumeEvent::class)->consume($id, 'platform.audit');
    $this->artisan('outbox:retry', ['event' => $id, 'consumer' => 'platform.audit'])->assertExitCode(1);
    expect(DB::table('audit_events')->where('id', $id)->count())->toBe(1);
    expect(DB::table('consumer_receipts')->where('event_id', $id)->value('status'))->toBe('complete');
});
