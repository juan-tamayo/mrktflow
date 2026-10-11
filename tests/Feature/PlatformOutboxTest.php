<?php

declare(strict_types=1);

use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

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
