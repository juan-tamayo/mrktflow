<?php

declare(strict_types=1);

namespace App\Domain\Platform\Support;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TestDatabaseGuard
{
    /** @param array<string, mixed> $connection */
    public static function assertConfiguration(string $environment, string $name, array $connection, bool $cached): void
    {
        $database = $connection['database'] ?? null;

        if ($environment !== 'testing' || $cached || $name !== 'mysql'
            || ($connection['driver'] ?? null) !== 'mysql'
            || ! in_array($database, ['mrktflow_test', 'mrktflow_browser'], true)
            || ($connection['username'] ?? null) !== $database
            || ! is_string($connection['password'] ?? null) || $connection['password'] === ''
            || ! empty($connection['url']) || isset($connection['read']) || isset($connection['write'])) {
            throw new RuntimeException('Unsafe test database configuration. Use the isolated test account and database without cached configuration or URL overrides.');
        }
    }

    /** @param list<string> $grants */
    public static function assertGrants(string $database, array $grants, bool $literalDatabaseNames): void
    {
        if (! $literalDatabaseNames || $grants === []) {
            throw new RuntimeException('Test database grants require MySQL partial_revokes=ON and explicit database-only privileges.');
        }

        $hasDatabaseGrant = false;
        foreach ($grants as $grant) {
            if (str_contains($grant, 'WITH GRANT OPTION')) {
                throw new RuntimeException('Test accounts may not delegate privileges.');
            }

            if (str_starts_with($grant, 'GRANT USAGE ON *.* TO ')) {
                continue;
            }

            if (! preg_match('/^GRANT [A-Z_, ]+ ON `'.preg_quote($database, '/').'`\.\* TO /', $grant)) {
                throw new RuntimeException('Test account has privileges outside its isolated database.');
            }

            $hasDatabaseGrant = true;
        }

        if (! $hasDatabaseGrant) {
            throw new RuntimeException('Test database privileges are missing.');
        }
    }

    public static function verify(Application $application): void
    {
        $connection = config()->array('database.connections.mysql');
        self::assertConfiguration($application->environment(), config()->string('database.default'), $connection, $application->configurationIsCached());

        $database = config()->string('database.connections.mysql.database');
        $server = DB::selectOne('SELECT VERSION() AS version, @@partial_revokes AS literal_names, DATABASE() AS database_name');
        if ($server === null || ! str_starts_with($server->version, '8.4.') || $server->database_name !== $database) {
            throw new RuntimeException('Tests require the isolated MySQL 8.4 database.');
        }

        $grants = array_map(static fn (object $row): string => (string) array_values((array) $row)[0], DB::select('SHOW GRANTS FOR CURRENT_USER'));
        self::assertGrants($database, array_values($grants), (bool) $server->literal_names);

        if (config('mail.default') !== 'array' || config('queue.default') !== 'sync') {
            throw new RuntimeException('Tests require array mail and synchronous jobs to prevent external side effects.');
        }
    }

    public static function assertBrowserMailFixture(string $environment, string $database, string $account, string $mailer): void
    {
        if ($environment !== 'testing'
            || $database !== 'mrktflow_browser'
            || preg_match('/^mrktflow_browser@[^@\s]+$/', $account) !== 1
            || $mailer !== 'array') {
            throw new RuntimeException('Only the guarded browser fixture runtime may read in-memory mail.');
        }
    }
}
