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

$environment = Dotenv::parse(file_get_contents($path));
if (($environment['APP_ENV'] ?? '') !== 'testing'
    || ($environment['DB_DATABASE'] ?? '') !== 'mrktflow_browser'
    || ($environment['DB_USERNAME'] ?? '') !== 'mrktflow_browser'
    || is_file($root.'/bootstrap/cache/config.php')
    || is_file($root.'/public/hot')) {
    throw new RuntimeException('Browser tests require the isolated browser environment, uncached config and built assets.');
}

$migrations = new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], $root, $environment);
$migrations->setTimeout(60)->mustRun();

$server = new Process([
    PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:8011', '-t', $root.'/public',
    $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
], $root.'/public', $environment);
$server->setTimeout(null);
exit($server->run(static function (string $type, string $buffer): void {
    fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
}));
