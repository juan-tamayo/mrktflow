<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

afterEach(function (): void {
    RefreshDatabaseState::$migrated = false;
});

it('PlatformEnvironment uses real MySQL and cannot access the development database', function (): void {
    expect(DB::selectOne('SELECT DATABASE() AS name')->name)->toBe('mrktflow_test');

    expect(fn () => DB::statement('CREATE TABLE mrktflow_dev.forbidden_test_write (id INT)'))
        ->toThrow(QueryException::class);
});

it('PlatformEnvironment can run and reverse migrations only inside the test database', function (): void {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    expect(Schema::hasTable('sessions'))->toBeTrue();
    $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();
    expect(Schema::hasTable('sessions'))->toBeFalse();
});
