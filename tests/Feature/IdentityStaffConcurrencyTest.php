<?php

declare(strict_types=1);

use App\Application\Setup\CreateInitialOwner;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Platform\Actions\InstallStore;
use App\Domain\Platform\Actions\SetupAccess;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

it('IdentityStaff serializes simultaneous owner demotions across real MySQL connections', function (): void {
    $access = app(SetupAccess::class);
    $grant = $access->unlock($access->issueSecret());
    app(InstallStore::class)->save($grant, json_decode(file_get_contents(base_path('tests/Fixtures/store-settings.json')), true, flags: JSON_THROW_ON_ERROR), 0);
    $password = 'Fictional owner passphrase 471!';
    $first = app(CreateInitialOwner::class)->create($grant, ['name' => 'First Owner', 'email' => 'first@example.test', 'password' => $password, 'password_confirmation' => $password]);
    $second = new StaffAccount;
    $second->forceFill(['name' => 'Second Owner', 'email' => 'second@example.test', 'password' => Hash::make($password), 'role' => 'owner', 'permissions' => StaffPermissions::forRole('owner'), 'is_active' => true])->save();
    $processes = [];
    $readyFiles = [];
    DB::beginTransaction();
    try {
        DB::table('staff_security')->where('id', 1)->lockForUpdate()->first();
        foreach ([$first, $second] as $owner) {
            $ready = base_path('.runtime/staff-race-'.$owner->id.'.ready');
            @unlink($ready);
            $readyFiles[] = $ready;
            $process = new Process([base_path('bin/php'), base_path('tests/Fixtures/staff-race.php'), $owner->id, '--env=testing'], base_path(), ['APP_ENV' => 'testing']);
            $process->setTimeout(15)->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 8;
        while ((! is_file($readyFiles[0]) || ! is_file($readyFiles[1])) && microtime(true) < $deadline) {
            expect($processes[0]->isRunning() && $processes[1]->isRunning())->toBeTrue();
            usleep(20000);
        }
        expect(is_file($readyFiles[0]) && is_file($readyFiles[1]))->toBeTrue();
        expect($processes[0]->isRunning() && $processes[1]->isRunning())->toBeTrue();
        DB::commit();
        $statuses = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0);
            $statuses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
        }
        sort($statuses);
        expect($statuses)->toBe([200, 409]);
        expect(StaffAccount::query()->where('role', 'owner')->where('is_active', true)->count())->toBe(1);
        expect(StaffAccount::query()->where('role', 'manager')->where('session_version', 2)->count())->toBe(1);
        expect(DB::table('outbox_events')->where('intent_key', 'like', 'staff:access:%')->count())->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        foreach ($readyFiles as $ready) {
            @unlink($ready);
        }
    }
});
