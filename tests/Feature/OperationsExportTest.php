<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Operations\Actions\DownloadStoreExport;
use App\Domain\Operations\Actions\PruneExpiredExports;
use App\Domain\Operations\Actions\QueueStoreExport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{owner: StaffAccount, formula: string}
 */
function operationsExportFixture(): array
{
    $catalog = refundCatalog();
    $formula = '=HYPERLINK("http://evil.test")';
    $image = catalogReadyImage($catalog['owner']->id);
    app(SaveProduct::class)->save($catalog['owner'], catalogProductInput($image, [
        'title' => $formula,
        'slug' => 'formula-lamp',
        'sku' => 'FORMULA-1',
        'status' => 'published',
        'price_amount' => 1200,
    ]));
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => $formula,
        'email' => 'export-shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $paid = refundPaidPhysical($catalog['physical_variant'], 'guest-export-live', 'cs_export_live', 'pi_export_live');
    DB::table('orders')->where('id', $paid['order_id'])->update(['email' => $formula]);

    return ['owner' => $catalog['owner'], 'formula' => $formula];
}

it('OperationsExport writes audited formula-safe private CSVs and expires other-user access', function (): void {
    $fixture = operationsExportFixture();
    $owner = $fixture['owner'];
    $queue = app(QueueStoreExport::class);
    $customers = $queue->queue($owner, ['dataset' => 'customers']);
    $orders = $queue->queue($owner, ['dataset' => 'orders']);
    $products = $queue->queue($owner, ['dataset' => 'products']);
    expect($customers['status'])->toBe('ready')
        ->and($orders['status'])->toBe('ready')
        ->and($products['status'])->toBe('ready');

    foreach ([$customers, $orders, $products] as $export) {
        $key = 'exports/'.$export['id'].'.csv';
        expect(Storage::disk('local')->exists($key))->toBeTrue()
            ->and(Storage::disk('public')->exists($key))->toBeFalse();
        $csv = Storage::disk('local')->get($key);
        expect($csv)->toContain("'".$fixture['formula'])
            ->and($csv)->not->toMatch('/(?:^|,)=HYPERLINK/m')
            ->and($csv)->not->toContain($owner->password);
        $audit = DB::table('outbox_events')->where('intent_key', 'audit:export-queued:'.$export['id'])->first();
        expect($audit)->not->toBeNull()->and($audit->event_type)->toBe('platform.audit.recorded');
        $this->get('/storage/'.$key)->assertNotFound();
    }

    $download = app(DownloadStoreExport::class)->download($owner, $customers['id'], $customers['token']);
    expect($download['csv'])->toContain('export-shopper@example.test')
        ->and($download['headers']['Cache-Control'])->toContain('private')
        ->and($download['headers']['Cache-Control'])->toContain('no-store');
    expect(DB::table('outbox_events')->where('intent_key', 'audit:export-downloaded:'.$customers['id'])->exists())->toBeTrue();

    $other = new StaffAccount;
    $other->forceFill([
        'name' => 'Other manager',
        'email' => 'other-export@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'manager',
        'permissions' => StaffPermissions::forRole('manager'),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    expect(fn () => app(DownloadStoreExport::class)->download($other, $customers['id'], $customers['token']))
        ->toThrow(HttpException::class);

    Carbon::setTestNow(Carbon::parse('2026-10-17T15:00:00Z')->utc());
    expect(fn () => app(DownloadStoreExport::class)->download($owner, $products['id'], $products['token']))
        ->toThrow(HttpException::class);
    expect(app(PruneExpiredExports::class)->prune())->toBe(3)
        ->and(Storage::disk('local')->exists('exports/'.$products['id'].'.csv'))->toBeFalse()
        ->and((string) DB::table('export_jobs')->where('id', $products['id'])->value('status'))->toBe('expired');
    $this->get('/')->assertStatus(503);
});

it('OperationsExport HTTP allows exports.read, denies other staff and customers, and still requires owner 2FA', function (): void {
    $fixture = operationsExportFixture();
    $owner = $fixture['owner'];
    $customer = CustomerAccount::query()->where('email', 'export-shopper@example.test')->firstOrFail();
    $this->get('/admin/exports')->assertRedirect('/admin/login');
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->getJson('/admin/exports')->assertUnauthorized();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version])
        ->get('/admin/exports')->assertRedirect('/admin/two-factor');
    $this->post('/admin/exports', ['dataset' => 'customers'])->assertRedirect('/admin/two-factor');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canExport', true));
    $this->get('/admin/exports')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Exports')
        ->where('retentionHours', 24)
        ->has('jobs', 0));
    $this->from('/admin/exports')->post('/admin/exports', ['dataset' => 'products'])->assertRedirect('/admin/exports');
    $jobId = (string) DB::table('export_jobs')->value('id');
    $tokens = session('export_download_tokens');
    expect($tokens)->toBeArray()->and($tokens[$jobId] ?? null)->toBeString();
    $url = '/admin/exports/'.$jobId.'/download?token='.$tokens[$jobId];
    $this->get('/admin/exports')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Exports')
        ->where('jobs.0.dataset', 'products')
        ->where('jobs.0.status', 'ready')
        ->where('jobs.0.download_url', $url));
    $file = $this->get($url);
    $file->assertOk();
    expect($file->headers->get('content-type'))->toStartWith('text/csv')
        ->and($file->headers->get('cache-control'))->toContain('private')
        ->and($file->headers->get('cache-control'))->toContain('no-store')
        ->and($file->streamedContent())->toContain("'=HYPERLINK")
        ->and($file->streamedContent())->toContain('FORMULA-1');

    $fulfillment = new StaffAccount;
    $fulfillment->forceFill([
        'name' => 'Shipper',
        'email' => 'shipper-export@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'fulfillment',
        'permissions' => StaffPermissions::forRole('fulfillment'),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $this->actingAs($fulfillment, 'staff')->withSession(catalogStaffSession($fulfillment))
        ->getJson('/admin/exports')->assertForbidden();
    $this->actingAs($fulfillment, 'staff')->withSession(catalogStaffSession($fulfillment))
        ->getJson($url)->assertForbidden();
    expect(fn () => app(QueueStoreExport::class)->queue($fulfillment, ['dataset' => 'orders']))
        ->toThrow(AuthorizationException::class);

    $limited = new StaffAccount;
    $limited->forceFill([
        'name' => 'Catalog only',
        'email' => 'catalog-export@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'manager',
        'permissions' => ['admin.access', 'exports.read', 'catalog.read'],
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    expect(fn () => app(QueueStoreExport::class)->queue($limited, ['dataset' => 'customers']))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(QueueStoreExport::class)->queue($limited, ['dataset' => 'orders']))
        ->toThrow(AuthorizationException::class);

    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner))
        ->get('/admin/exports/'.$jobId.'/download')->assertNotFound();
    $this->actingAs($limited, 'staff')->withSession(catalogStaffSession($limited))
        ->getJson($url)->assertNotFound();
    $this->get('/')->assertStatus(503);
});
