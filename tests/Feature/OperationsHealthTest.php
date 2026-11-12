<?php

declare(strict_types=1);

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Operations\Actions\BuildSupportExport;
use App\Domain\Operations\Actions\InspectHealth;
use App\Domain\Operations\Actions\ListAuditLog;
use App\Domain\Operations\Actions\RecordBackupObservation;
use App\Domain\Platform\Actions\PlatformHealth;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-16T14:00:00Z')->utc());
    config([
        'app.url' => 'http://127.0.0.1:8000',
        'services.stripe.secret' => 'sk_test_fixture',
        'services.stripe.account' => 'acct_fixture',
        'services.stripe.live' => true,
        'operations.health.disk_fixture' => [
            'path' => '/srv/mrktflow/storage',
            'free_bytes' => 90,
            'total_bytes' => 100,
            'used_percent' => 10,
        ],
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{owner: StaffAccount}
 */
function operationsHealthOwner(): array
{
    $owner = catalogInstalledOwner();
    app(PlatformHealth::class)->heartbeat();
    app(RecordBackupObservation::class)->record();

    return ['owner' => $owner];
}

function operationsHealthFailedJob(string $uuid, string $payloadSecret, string $exceptionSecret): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'payments',
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\ReconcilePendingPayments',
            'data' => ['email' => $payloadSecret, 'secret' => 'sk_live_support_secret_fixture'],
        ], JSON_THROW_ON_ERROR),
        'exception' => "RuntimeException: {$exceptionSecret} APP_KEY=base64:leakfixture\n#0 /app/Jobs/ReconcilePendingPayments.php(29)",
        'failed_at' => now(),
    ]);
}

function operationsHealthUnknownPayment(string $orderId): string
{
    $id = (string) Str::uuid();
    DB::table('payment_attempts')->insert([
        'id' => $id,
        'order_id' => $orderId,
        'status' => 'unknown',
        'amount' => 2500,
        'currency' => 'USD',
        'exponent' => 2,
        'provider' => 'stripe',
        'provider_session_id' => 'cs_unknown_health',
        'expires_at' => now()->addMinutes(30),
        'created_at' => now()->subMinutes(15),
        'updated_at' => now()->subMinutes(15),
        'live' => true,
    ]);

    return $id;
}

it('OperationsHealth warns when the scheduler and worker lag, payments stay unknown, backups age, and disk is near full', function (): void {
    $catalog = refundCatalog();
    $owner = $catalog['owner'];
    app(PlatformHealth::class)->heartbeat();
    app(RecordBackupObservation::class)->record();
    $paid = refundPaidPhysical($catalog['physical_variant'], 'guest-health-unknown', 'cs_health_live', 'pi_health_live');
    operationsHealthUnknownPayment($paid['order_id']);
    operationsHealthFailedJob(
        '11111111-1111-4111-8111-111111111111',
        'customer-payload@leak.test',
        'card 4242424242424242 for customer-payload@leak.test',
    );
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['data' => ['email' => 'customer-payload@leak.test']], JSON_THROW_ON_ERROR),
        'attempts' => 1,
        'available_at' => now()->subMinutes(8)->getTimestamp(),
        'created_at' => now()->subMinutes(8)->getTimestamp(),
    ]);
    DB::table('platform_heartbeats')->where('name', 'scheduler')->update([
        'observed_at' => now()->subMinutes(4),
    ]);
    DB::table('platform_heartbeats')->where('name', 'backup')->update([
        'observed_at' => now()->subHours(25),
    ]);
    config(['operations.health.disk_fixture' => [
        'path' => '/srv/mrktflow/storage',
        'free_bytes' => 5,
        'total_bytes' => 100,
        'used_percent' => 95,
    ]]);

    $report = app(InspectHealth::class)->forOwner($owner);
    $codes = array_column($report['warnings'], 'code');
    expect($report['version'])->toBe('1.0.0')
        ->and($report['scheduler']['warning'])->toBeTrue()
        ->and($report['scheduler']['lag_seconds'])->toBeGreaterThanOrEqual(180)
        ->and($report['worker']['warning'])->toBeTrue()
        ->and($report['worker']['oldest_due_seconds'])->toBeGreaterThanOrEqual(300)
        ->and($report['payments']['unknown_count'])->toBe(1)
        ->and($report['payments']['warning'])->toBeTrue()
        ->and($report['payments']['oldest_unknown_seconds'])->toBeGreaterThanOrEqual(600)
        ->and($report['jobs']['failed'])->toBe(1)
        ->and($report['jobs']['errors'][0]['uuid'])->toBe('11111111-1111-4111-8111-111111111111')
        ->and($report['jobs']['errors'][0]['queue'])->toBe('payments')
        ->and($report['jobs']['errors'][0]['error_code'])->toBe('RuntimeException')
        ->and(json_encode($report))->not->toContain('customer-payload@leak.test')
        ->and(json_encode($report))->not->toContain('sk_live_support_secret_fixture')
        ->and(json_encode($report))->not->toContain('4242424242424242')
        ->and(json_encode($report))->not->toContain('base64:leakfixture')
        ->and($report['disk']['used_percent'])->toBe(95)
        ->and($report['disk']['warning'])->toBeTrue()
        ->and($report['backup']['warning'])->toBeTrue()
        ->and($codes)->toContain('scheduler_stale')
        ->and($codes)->toContain('worker_lag')
        ->and($codes)->toContain('unknown_payments')
        ->and($codes)->toContain('failed_jobs')
        ->and($codes)->toContain('backup_stale')
        ->and($codes)->toContain('disk_full');

    $this->artisan('operations:health')
        ->expectsOutputToContain('WARN scheduler_stale')
        ->expectsOutputToContain('WARN worker_lag')
        ->expectsOutputToContain('WARN unknown_payments')
        ->expectsOutputToContain('WARN backup_stale')
        ->expectsOutputToContain('WARN disk_full')
        ->expectsOutputToContain('RuntimeException')
        ->assertExitCode(1);
    $output = Artisan::output();
    expect($output)->not->toContain('customer-payload@leak.test')
        ->and($output)->not->toContain('sk_live_support_secret_fixture');
});

it('OperationsHealth support export excludes private payloads and lists allowlisted audit rows', function (): void {
    $owner = operationsHealthOwner()['owner'];
    operationsHealthFailedJob(
        '22222222-2222-4222-8222-222222222222',
        'export-payload@leak.test',
        'secret token tok_live_export',
    );
    DB::table('audit_events')->insert([
        'id' => (string) Str::uuid(),
        'action' => 'staff.created',
        'subject_type' => 'staff',
        'subject_id' => $owner->id,
        'actor_type' => 'system',
        'actor_id' => null,
        'metadata' => json_encode(['version' => 1], JSON_THROW_ON_ERROR),
        'correlation_id' => (string) Str::uuid(),
        'occurred_at' => now(),
    ]);
    $export = app(BuildSupportExport::class)->forOwner($owner);
    expect($export['filename'])->toBe('support-diagnostics.json')
        ->and($export['headers']['Cache-Control'])->toContain('private')
        ->and($export['json'])->toContain('"version":"1.0.0"')
        ->and($export['json'])->toContain('RuntimeException')
        ->and($export['json'])->toContain('staff.created')
        ->and($export['json'])->not->toContain('export-payload@leak.test')
        ->and($export['json'])->not->toContain('tok_live_export')
        ->and($export['json'])->not->toContain('sk_live_support_secret_fixture')
        ->and($export['json'])->not->toContain($owner->password);
    expect(json_decode($export['json'], true, flags: JSON_THROW_ON_ERROR)['failed_jobs'][0])->not->toHaveKey('payload')
        ->and(json_decode($export['json'], true, flags: JSON_THROW_ON_ERROR)['failed_jobs'][0])->not->toHaveKey('exception');
    expect(DB::table('outbox_events')->where('intent_key', 'like', 'audit:support-export:%')->exists())->toBeTrue();
    $log = app(ListAuditLog::class)->forOwner($owner);
    expect(array_column($log, 'action'))->toContain('staff.created')
        ->and(json_encode($log))->not->toContain($owner->password);
});

it('OperationsHealth HTTP is owner-only, still requires 2FA, and keeps GET / at 503', function (): void {
    $owner = operationsHealthOwner()['owner'];
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper-health@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->get('/admin/health')->assertRedirect('/admin/login');
    $this->get('/admin/audit')->assertRedirect('/admin/login');
    $this->post('/admin/health/support-export')->assertRedirect('/admin/login');
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->getJson('/admin/health')->assertUnauthorized();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version])
        ->get('/admin/health')->assertRedirect('/admin/two-factor');
    $this->get('/admin/audit')->assertRedirect('/admin/two-factor');
    $this->post('/admin/health/support-export')->assertRedirect('/admin/two-factor');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canInspectHealth', true));
    $this->get('/admin/health')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('SystemHealth')
        ->where('health.version', '1.0.0')
        ->where('health.scheduler.warning', false)
        ->where('health.backup.warning', false)
        ->has('health.warnings'));
    $this->get('/admin/audit')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AuditLog')
        ->has('events'));
    $download = $this->from('/admin/health')->post('/admin/health/support-export');
    $download->assertOk();
    expect($download->headers->get('content-type'))->toStartWith('application/json')
        ->and($download->headers->get('cache-control'))->toContain('private')
        ->and($download->headers->get('cache-control'))->toContain('no-store')
        ->and($download->streamedContent())->toContain('"version":"1.0.0"');

    $manager = new StaffAccount;
    $manager->forceFill([
        'name' => 'Manager', 'email' => 'manager-health@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'manager', 'permissions' => StaffPermissions::forRole('manager'),
        'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($manager, 'staff')->withSession(catalogStaffSession($manager))
        ->getJson('/admin/health')->assertForbidden();
    $this->actingAs($manager, 'staff')->withSession(catalogStaffSession($manager))
        ->getJson('/admin/audit')->assertForbidden();
    $this->actingAs($manager, 'staff')->withSession(catalogStaffSession($manager))
        ->postJson('/admin/health/support-export')->assertForbidden();
    expect(fn () => app(InspectHealth::class)->forOwner($manager))->toThrow(AuthorizationException::class);
    expect(fn () => app(ListAuditLog::class)->forOwner($manager))->toThrow(AuthorizationException::class);
    expect(fn () => app(BuildSupportExport::class)->forOwner($manager))->toThrow(AuthorizationException::class);
    $this->get('/')->assertStatus(503);
});
