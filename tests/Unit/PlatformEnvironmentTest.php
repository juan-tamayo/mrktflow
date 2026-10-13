<?php

declare(strict_types=1);

use App\Domain\Platform\Support\TestDatabaseGuard;

function isolatedDatabaseConfig(): array
{
    return [
        'driver' => 'mysql', 'database' => 'mrktflow_test',
        'username' => 'mrktflow_test', 'password' => 'test-only-secret',
        'url' => null, 'host' => '127.0.0.1',
    ];
}

it('PlatformEnvironment accepts only the explicitly isolated database and account', function (): void {
    expect(fn () => TestDatabaseGuard::assertConfiguration('testing', 'mysql', isolatedDatabaseConfig(), false))
        ->not->toThrow(RuntimeException::class);
});

it('PlatformEnvironment rejects a production environment before connecting', function (): void {
    TestDatabaseGuard::assertConfiguration('production', 'mysql', isolatedDatabaseConfig(), false);
})->throws(RuntimeException::class);

it('PlatformEnvironment rejects unsafe connection settings', function (array $changes): void {
    TestDatabaseGuard::assertConfiguration('testing', 'mysql', array_replace(isolatedDatabaseConfig(), $changes), false);
})->with([
    'merchant database' => [['database' => 'merchant']],
    'development database' => [['database' => 'mrktflow_dev']],
    'root account' => [['username' => 'root']],
    'different account' => [['username' => 'mrktflow_dev']],
    'URL override' => [['url' => 'mysql://elsewhere/merchant']],
    'missing password' => [['password' => '']],
    'read replica' => [['read' => ['database' => 'merchant']]],
    'wrong driver' => [['driver' => 'sqlite']],
])->throws(RuntimeException::class);

it('PlatformEnvironment rejects cached configuration', function (): void {
    TestDatabaseGuard::assertConfiguration('testing', 'mysql', isolatedDatabaseConfig(), true);
})->throws(RuntimeException::class);

it('PlatformEnvironment rejects a second default connection', function (): void {
    TestDatabaseGuard::assertConfiguration('testing', 'merchant', isolatedDatabaseConfig(), false);
})->throws(RuntimeException::class);

it('PlatformEnvironment accepts grants restricted to the exact test database', function (): void {
    expect(fn () => TestDatabaseGuard::assertGrants('mrktflow_test', [
        'GRANT USAGE ON *.* TO `mrktflow_test`@`localhost`',
        'GRANT ALL PRIVILEGES ON `mrktflow_test`.* TO `mrktflow_test`@`localhost`',
    ], true))->not->toThrow(RuntimeException::class);
});

it('PlatformEnvironment rejects broad or ambiguous grants', function (array $grants, bool $literalNames): void {
    TestDatabaseGuard::assertGrants('mrktflow_test', $grants, $literalNames);
})->with([
    'global privileges' => [['GRANT ALL PRIVILEGES ON *.* TO `mrktflow_test`@`localhost`'], true],
    'other database' => [['GRANT ALL PRIVILEGES ON `mrktflow_dev`.* TO `mrktflow_test`@`localhost`'], true],
    'delegation' => [['GRANT ALL PRIVILEGES ON `mrktflow_test`.* TO `mrktflow_test`@`localhost` WITH GRANT OPTION'], true],
    'role grant' => [['GRANT `administrator`@`%` TO `mrktflow_test`@`localhost`'], true],
    'wildcard semantics' => [['GRANT ALL PRIVILEGES ON `mrktflow_test`.* TO `mrktflow_test`@`localhost`'], false],
    'empty' => [[], true],
])->throws(RuntimeException::class);

it('PlatformEnvironment accepts the browser mail fixture for socket and TCP accounts', function (string $account): void {
    expect(fn () => TestDatabaseGuard::assertBrowserMailFixture('testing', 'mrktflow_browser', $account, 'array'))
        ->not->toThrow(RuntimeException::class);
})->with([
    'local socket' => ['mrktflow_browser@localhost'],
    'disposable CI host' => ['mrktflow_browser@%'],
]);

it('PlatformEnvironment rejects an unsafe browser mail fixture runtime', function (string $environment, string $database, string $account, string $mailer): void {
    TestDatabaseGuard::assertBrowserMailFixture($environment, $database, $account, $mailer);
})->with([
    'production' => ['production', 'mrktflow_browser', 'mrktflow_browser@localhost', 'array'],
    'php test database' => ['testing', 'mrktflow_test', 'mrktflow_test@localhost', 'array'],
    'development database' => ['testing', 'mrktflow_dev', 'mrktflow_browser@localhost', 'array'],
    'root account' => ['testing', 'mrktflow_browser', 'root@localhost', 'array'],
    'smtp mailer' => ['testing', 'mrktflow_browser', 'mrktflow_browser@localhost', 'smtp'],
])->throws(RuntimeException::class);
