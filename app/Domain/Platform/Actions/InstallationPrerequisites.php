<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Throwable;

final class InstallationPrerequisites
{
    /** @return list<array{id: string, passed: bool, required: bool, message: string}> */
    public function report(): array
    {
        $extensions = ['pdo_mysql', 'openssl', 'mbstring', 'intl', 'fileinfo', 'gd', 'zip', 'dom'];
        $version = phpversion();
        $compatiblePhp = version_compare($version, '8.4.0', '>=') && version_compare($version, '8.5.0', '<');
        $extensionsAvailable = array_all($extensions, static fn (string $extension): bool => extension_loaded($extension));
        $url = parse_url(config()->string('app.url'));
        $local = in_array(config('app.env'), ['local', 'testing'], true);
        $validUrl = is_array($url) && isset($url['host'], $url['scheme'])
            && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment'])
            && ($url['scheme'] === 'https' || ($local && $url['scheme'] === 'http' && in_array($url['host'], ['127.0.0.1', 'localhost', '::1'], true)));
        $private = realpath(config()->string('filesystems.disks.local.root'));
        $public = realpath(public_path());
        $privateStorage = $private !== false && $public !== false && is_writable($private)
            && $private !== $public && ! str_starts_with($private, $public.DIRECTORY_SEPARATOR)
            && config('filesystems.disks.local.driver') === 'local' && config('filesystems.disks.local.serve') === false;
        $writable = array_all([storage_path('framework/views'), storage_path('framework/cache'), storage_path('logs'), base_path('bootstrap/cache')], static fn (string $path): bool => is_writable($path));
        $database = false;
        try {
            $server = DB::transaction(fn () => DB::selectOne('SELECT VERSION() AS version, @@default_storage_engine AS engine'));
            $database = $server !== null && str_starts_with($server->version, '8.4.') && strtoupper($server->engine) === 'INNODB';
        } catch (Throwable) {
            // Return a remedy rather than database credentials, SQL or exception text.
        }
        $key = config('app.key');
        $validKey = is_string($key) && str_starts_with($key, 'base64:') && strlen(base64_decode(substr($key, 7), true) ?: '') === 32;

        return [
            ['id' => 'php', 'passed' => $compatiblePhp && $extensionsAvailable, 'required' => true, 'message' => 'Use PHP 8.4 with PDO MySQL, OpenSSL, mbstring, intl, fileinfo, GD, zip and DOM.'],
            ['id' => 'url', 'passed' => $validUrl, 'required' => true, 'message' => 'Set APP_URL to the public HTTPS address. Local HTTP is allowed only on loopback in development/testing.'],
            ['id' => 'key', 'passed' => $validKey, 'required' => true, 'message' => 'Generate an application key for a fresh install; preserve the existing key on upgrades.'],
            ['id' => 'database', 'passed' => $database, 'required' => true, 'message' => 'Configure a dedicated MySQL 8.4 database using InnoDB and run the migrations.'],
            ['id' => 'private_storage', 'passed' => $privateStorage, 'required' => true, 'message' => 'Provide writable private local storage outside the public web directory; disable generic file serving.'],
            ['id' => 'writable', 'passed' => $writable, 'required' => true, 'message' => 'Allow the application to write its view/cache/log and bootstrap cache directories.'],
            ['id' => 'queue', 'passed' => config('queue.default') === 'database', 'required' => false, 'message' => 'Configure the database queue and supervised workers before opening the store. Worker health is verified separately.'],
            ['id' => 'mail', 'passed' => ! in_array(config('mail.default'), ['array', 'log'], true), 'required' => false, 'message' => 'Configure and verify a transactional sender before opening the store. Test transports do not deliver email.'],
            ['id' => 'scheduler', 'passed' => false, 'required' => false, 'message' => 'Install the minute scheduler and verify its heartbeat before opening the store.'],
        ];
    }

    public function assertReady(): void
    {
        foreach ($this->report() as $check) {
            abort_if($check['required'] && ! $check['passed'], 409, $check['message']);
        }
    }
}
