<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$path = $root.'/.env.browser';
if (! is_file($path)) {
    throw new RuntimeException('Create a private .env.browser using the isolated browser database.');
}

$contents = file_get_contents($path);
if ($contents === false) {
    throw new RuntimeException('The private browser environment cannot be read.');
}
$environment = Dotenv::parse($contents);
foreach ($environment as $value) {
    if (! is_string($value)) {
        throw new RuntimeException('Browser environment entries must have explicit string values.');
    }
}
if (($environment['APP_ENV'] ?? '') !== 'testing'
    || ($environment['DB_DATABASE'] ?? '') !== 'mrktflow_browser'
    || ($environment['DB_USERNAME'] ?? '') !== 'mrktflow_browser'
    || is_file($root.'/bootstrap/cache/config.php')
    || is_file($root.'/public/hot')) {
    throw new RuntimeException('Browser tests require the isolated browser environment, uncached config and built assets.');
}

$migrations = new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], $root, $environment);
$migrations->setTimeout(60)->mustRun();

$secretCommand = new Process([PHP_BINARY, 'artisan', 'setup:secret', '--no-interaction'], $root, $environment);
$secretCommand->setTimeout(30)->mustRun();
if (! preg_match('/^([a-f0-9]{64})$/m', $secretCommand->getOutput(), $matches)) {
    throw new RuntimeException('Could not prepare the isolated setup fixture.');
}
if (! is_dir($root.'/.runtime') && ! mkdir($root.'/.runtime', 0700, true)) {
    throw new RuntimeException('Could not create the private fixture directory.');
}
umask(0077);
$secretPath = $root.'/.runtime/browser-setup-secret';
if (file_put_contents($secretPath, $matches[1], LOCK_EX) === false || ! chmod($secretPath, 0600)) {
    throw new RuntimeException('Could not create the private setup fixture.');
}

$server = new Process([
    PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:8011', '-t', $root.'/public',
    $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
], $root.'/public', $environment);
$server->setTimeout(null);
exit($server->run(static function (string $type, string $buffer): void {
    fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
}));
