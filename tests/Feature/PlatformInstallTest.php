<?php

declare(strict_types=1);

use App\Domain\Platform\Actions\InstallationPrerequisites;
use App\Domain\Platform\Actions\InstallStore;
use App\Domain\Platform\Actions\SetupAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('PlatformInstall issues a high-entropy secret and stores only its digest', function (): void {
    $secret = app(SetupAccess::class)->issueSecret();
    expect(strlen($secret))->toBe(64);
    $installation = DB::table('installations')->first();
    expect($installation->secret_digest)->toBe(hash('sha256', $secret));
    expect(json_encode($installation))->not->toContain($secret);
    expect(app(SetupAccess::class)->unlock($secret)['expires'])->toBe(now()->addMinutes(15)->timestamp);
});

it('PlatformInstall rejects missing incorrect and expired secrets', function (): void {
    $access = app(SetupAccess::class);
    expect(fn () => $access->unlock(str_repeat('0', 64)))->toThrow(HttpException::class);
    $secret = $access->issueSecret();
    expect(fn () => $access->unlock('wrong'))->toThrow(HttpException::class);
    $this->travel(25)->hours();
    expect(fn () => $access->unlock($secret))->toThrow(HttpException::class);
});

it('PlatformInstall revokes old secrets and setup grants on rotation', function (): void {
    $access = app(SetupAccess::class);
    $old = $access->issueSecret();
    $grant = $access->unlock($old);
    $new = $access->issueSecret();
    expect($new)->not->toBe($old);
    expect(fn () => $access->unlock($old))->toThrow(HttpException::class);
    expect(fn () => $access->authorize($grant))->toThrow(HttpException::class);
});

it('PlatformInstall expires setup access and refuses reopening a completed installation', function (): void {
    $access = app(SetupAccess::class);
    $secret = $access->issueSecret();
    $grant = $access->unlock($secret);
    $this->travel(16)->minutes();
    expect(fn () => $access->authorize($grant))->toThrow(HttpException::class);
    DB::table('installations')->where('id', 1)->update(['completed_at' => now(), 'status' => 'complete']);
    expect(fn () => $access->issueSecret())->toThrow(HttpException::class);
    expect(fn () => $access->unlock($secret))->toThrow(HttpException::class);
});

function installSettings(): array
{
    return [
        'name' => 'Example Supply', 'currency' => 'USD', 'locale' => 'en',
        'timezone' => 'America/Los_Angeles', 'contact_email' => 'help@example.test',
        'sender_email' => 'store@example.test', 'sender_name' => 'Example Supply',
        'sales_countries' => ['US', 'CA'], 'weight_unit' => 'kg', 'length_unit' => 'cm',
        'policies' => ['privacy' => 'Privacy policy', 'terms' => 'Terms', 'returns' => 'Return policy', 'shipping' => 'Shipping policy', 'digital' => 'Download policy'],
    ];
}

it('PlatformInstall saves settings atomically and resumes without duplicating versions', function (): void {
    $access = app(SetupAccess::class);
    $grant = $access->unlock($access->issueSecret());
    $installer = app(InstallStore::class);
    expect($installer->save($grant, installSettings(), 0))->toBe(1);
    expect($installer->save($grant, installSettings(), 0))->toBe(1);
    expect(DB::table('installations')->value('status'))->toBe('pending_owner');
    expect(DB::table('installations')->value('completed_at'))->toBeNull();
    expect(DB::table('store_settings')->count())->toBe(1);
    expect(fn () => $installer->save($grant, array_replace(installSettings(), ['name' => 'Changed']), 0))
        ->toThrow(HttpException::class);
    expect($installer->save($grant, array_replace(installSettings(), ['name' => 'Changed']), 1))->toBe(2);
});

it('PlatformInstall rejects invalid settings without partial state', function (array $changes): void {
    $access = app(SetupAccess::class);
    $grant = $access->unlock($access->issueSecret());
    expect(fn () => app(InstallStore::class)->save($grant, array_replace(installSettings(), $changes), 0))
        ->toThrow(ValidationException::class);
    expect(DB::table('store_settings')->count())->toBe(0);
    expect(DB::table('installations')->value('status'))->toBe('unconfigured');
})->with([
    'unknown currency' => [['currency' => 'ZZZ']],
    'invalid country' => [['sales_countries' => ['ZZ']]],
    'empty countries' => [['sales_countries' => []]],
    'invalid timezone' => [['timezone' => 'Somewhere/Else']],
    'bad email' => [['sender_email' => 'invalid']],
    'unknown units' => [['weight_unit' => 'ounces']],
    'unknown secret' => [['payment_secret' => 'untrusted']],
    'missing policies' => [['policies' => []]],
]);

it('PlatformInstall reports blocked prerequisites without exposing private paths', function (): void {
    config(['app.url' => 'http://shop.example.test', 'app.env' => 'production', 'filesystems.disks.local.root' => public_path()]);
    $report = app(InstallationPrerequisites::class)->report();
    $checks = collect($report)->keyBy('id');
    expect($checks['url']['passed'])->toBeFalse();
    expect($checks['private_storage']['passed'])->toBeFalse();
    expect($checks['database']['passed'])->toBeTrue();
    expect(json_encode($report))->not->toContain(base_path());
});

it('PlatformInstall refuses settings when required prerequisites fail', function (): void {
    $access = app(SetupAccess::class);
    $grant = $access->unlock($access->issueSecret());
    config(['filesystems.disks.local.root' => public_path()]);
    expect(fn () => app(InstallStore::class)->save($grant, installSettings(), 0))
        ->toThrow(HttpException::class);
    expect(DB::table('store_settings')->count())->toBe(0);
});

it('PlatformInstall exposes secret issuance only through the server command', function (): void {
    $this->artisan('setup:secret')->expectsOutputToContain('expires')->assertSuccessful();
    expect(DB::table('installations')->value('secret_digest'))->toBeString();
    $this->postJson('/setup/secret')->assertNotFound();
});

it('PlatformInstall blocks settings writes with forged or revoked setup access', function (): void {
    $access = app(SetupAccess::class);
    $grant = $access->unlock($access->issueSecret());
    $installer = app(InstallStore::class);
    expect(fn () => $installer->save(['generation' => 'forged', 'expires' => now()->addMinute()->timestamp], installSettings(), 0))->toThrow(HttpException::class);
    $access->issueSecret();
    expect(fn () => $installer->save($grant, installSettings(), 0))->toThrow(HttpException::class);
    expect(DB::table('store_settings')->count())->toBe(0);
});

it('PlatformInstall cannot mutate settings after the persistent completion lock', function (): void {
    $access = app(SetupAccess::class);
    $grant = $access->unlock($access->issueSecret());
    $installer = app(InstallStore::class);
    $installer->save($grant, installSettings(), 0);
    DB::table('installations')->where('id', 1)->update(['status' => 'complete', 'completed_at' => now()]);
    expect(fn () => $installer->save($grant, array_replace(installSettings(), ['name' => 'Hijacked']), 1))->toThrow(HttpException::class);
    expect(json_decode(DB::table('store_settings')->value('values'), true)['name'])->toBe('Example Supply');
});
