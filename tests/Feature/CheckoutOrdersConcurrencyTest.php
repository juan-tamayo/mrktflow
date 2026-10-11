<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

it('CheckoutOrdersConcurrency reserves the last synthetic unit once and rolls back allocations atomically', function (): void {
    Schema::create('spike_stock', function (Blueprint $table): void {
        $table->engine = 'InnoDB';
        $table->unsignedInteger('id')->primary();
        $table->unsignedInteger('available');
    });
    Schema::create('spike_reservations', function (Blueprint $table): void {
        $table->engine = 'InnoDB';
        $table->uuid('attempt_id')->primary();
        $table->unsignedInteger('quantity');
    });
    DB::table('spike_stock')->insert(['id' => 1, 'available' => 1]);
    $processes = [];
    $readyFiles = [];
    $start = function (bool $rollback = false) use (&$processes, &$readyFiles): Process {
        $id = (string) Str::uuid();
        $readyFiles[] = base_path('.runtime/checkout-'.$id.'.ready');
        $process = new Process([base_path('bin/php'), base_path('tests/Fixtures/checkout-reservation-race.php'), $id, '--env=testing'], base_path(), ['APP_ENV' => 'testing']);
        $process->setInput(json_encode(['rollback' => $rollback], JSON_THROW_ON_ERROR));
        $process->setTimeout(15)->start();
        $processes[] = $process;

        return $process;
    };
    try {
        DB::beginTransaction();
        DB::table('spike_stock')->where('id', 1)->lockForUpdate()->first();
        $start();
        $start();
        $deadline = microtime(true) + 8;
        while ((! is_file($readyFiles[0]) || ! is_file($readyFiles[1])) && microtime(true) < $deadline) {
            expect($processes[0]->isRunning() && $processes[1]->isRunning())->toBeTrue();
            usleep(20000);
        }
        expect(is_file($readyFiles[0]) && is_file($readyFiles[1]))->toBeTrue();
        expect($processes[0]->isRunning() && $processes[1]->isRunning())->toBeTrue();
        DB::commit();
        $results = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0);
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        $statuses = array_column($results, 'status');
        sort($statuses);
        expect($statuses)->toBe(['reserved', 'sold_out']);
        expect(count(array_unique(array_column($results, 'connection'))))->toBe(2);
        expect(array_column($results, 'connection'))->not->toContain(DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
        expect(DB::table('spike_stock')->value('available'))->toBe(0);
        expect(DB::table('spike_reservations')->count())->toBe(1);
        expect((int) DB::table('spike_reservations')->sum('quantity'))->toBe(1);

        DB::table('spike_stock')->update(['available' => 1]);
        DB::table('spike_reservations')->delete();
        $rollback = $start(true);
        expect($rollback->wait())->toBe(0);
        expect(json_decode($rollback->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'])->toBe('rolled_back');
        expect(DB::table('spike_stock')->value('available'))->toBe(1);
        expect(DB::table('spike_reservations')->count())->toBe(0);
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
            if (is_file($ready)) {
                unlink($ready);
            }
        }
        Schema::dropIfExists('spike_reservations');
        Schema::dropIfExists('spike_stock');
    }
});
