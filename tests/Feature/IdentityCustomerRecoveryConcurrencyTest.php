<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\IssueCustomerChallenge;
use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Models\CustomerAccount;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\CustomerRegistration;

uses(DatabaseMigrations::class);

it('IdentityCustomer consumes a recovery token once across simultaneous real MySQL connections', function (): void {
    DB::table('installations')->where('id', 1)->update(['status' => 'complete', 'completed_at' => now()]);
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $challenge = app(IssueCustomerChallenge::class)->passwordReset($customer->email);
    $passwords = ['Fictional first new passphrase 872!', 'Fictional second new passphrase 873!'];
    $processes = [];
    $readyFiles = [];
    DB::beginTransaction();
    try {
        CustomerAccount::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
        foreach ($passwords as $password) {
            $workerId = (string) Str::uuid();
            $ready = base_path('.runtime/customer-reset-'.$workerId.'.ready');
            $readyFiles[] = $ready;
            $process = new Process([base_path('bin/php'), base_path('tests/Fixtures/customer-reset-race.php'), $workerId, '--env=testing'], base_path(), ['APP_ENV' => 'testing']);
            // Synthetic credentials go through private stdin, never process arguments or readiness files.
            $process->setInput(json_encode(['token' => $challenge->token(), 'password' => $password], JSON_THROW_ON_ERROR));
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
        expect($statuses)->toBe([200, 404]);
        $current = $customer->fresh();
        expect($current->session_version)->toBe(2);
        expect($current->email_verified_at)->toBeNull();
        expect((int) Hash::check($passwords[0], $current->password) + (int) Hash::check($passwords[1], $current->password))->toBe(1);
        expect(DB::table('customer_challenges')->where('id', $challenge->id)->value('token_digest'))->toBeNull();
        expect(DB::table('outbox_events')->where('intent_key', 'customer:password_reset:'.$challenge->id)->count())->toBe(1);
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
