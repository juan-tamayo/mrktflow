<?php

declare(strict_types=1);

use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('PlatformCorrelation assigns server-owned request IDs to success and error responses', function (): void {
    $first = $this->withHeader('X-Request-ID', 'untrusted-request-id')->get('/up');
    expect(Str::isUuid($first->headers->get('X-Request-ID')))->toBeTrue();
    $error = $this->get('/missing-fixture');
    expect(Str::isUuid($error->headers->get('X-Request-ID')))->toBeTrue();
    expect($error->headers->get('X-Request-ID'))->not->toBe($first->headers->get('X-Request-ID'));
});

it('PlatformCorrelation propagates request identity into durable intent without logging private exception text', function (): void {
    $entries = [];
    Log::listen(function ($message) use (&$entries): void {
        $entries[] = [$message->message, $message->context];
    });
    Route::get('/correlation-fixture', function (): never {
        app(RecordEvent::class)->record('platform.audit.recorded', 'audit:request:1', ['action' => 'fixture.saved', 'subject_type' => 'fixture', 'subject_id' => '1']);
        throw new RuntimeException('private_fixture_token_123');
    });
    $response = $this->get('/correlation-fixture');
    $response->assertStatus(500);
    expect(DB::table('outbox_events')->value('correlation_id'))->toBe($response->headers->get('X-Request-ID'));
    expect(json_encode($entries))->not->toContain('private_fixture_token_123');
    expect($entries[0][0])->toBe('application.failed');
    expect($entries[0][1]['correlation_id'])->toBe($response->headers->get('X-Request-ID'));
});
