<?php

declare(strict_types=1);

// This helper provisions only a newly created, disposable CI database server.
if (getenv('CI') !== 'true' || getenv('MRKTFLOW_CI_DATABASE') !== 'ephemeral') {
    throw new RuntimeException('Refusing to provision outside an explicit disposable CI environment.');
}

$root = dirname(__DIR__);
foreach (['.env', '.env.testing', '.env.browser', 'bootstrap/cache/config.php'] as $path) {
    if (file_exists($root.'/'.$path)) {
        throw new RuntimeException('Refusing to overwrite an existing environment or use cached configuration.');
    }
}

$password = getenv('MYSQL_ROOT_PASSWORD');
if (! is_string($password) || strlen($password) < 32) {
    throw new RuntimeException('A fresh CI root secret is required.');
}

$connection = null;
for ($attempt = 0; $attempt < 60; $attempt++) {
    try {
        $connection = new PDO('mysql:host=127.0.0.1;port=33306;charset=utf8mb4', 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        break;
    } catch (PDOException) {
        usleep(500_000);
    }
}
if ($connection === null) {
    throw new RuntimeException('Disposable MySQL did not become ready.');
}
$literalNames = $connection->query('SELECT @@partial_revokes');
if ($literalNames === false || (int) $literalNames->fetchColumn() !== 1) {
    throw new RuntimeException('MySQL must start with partial_revokes=ON before creating grants.');
}

umask(0077);
foreach (['dev', 'test', 'browser'] as $purpose) {
    $database = 'mrktflow_'.$purpose;
    $secret = bin2hex(random_bytes(32));
    $connection->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $connection->exec("CREATE USER '$database'@'%' IDENTIFIED BY ".$connection->quote($secret));
    $connection->exec("GRANT ALL PRIVILEGES ON `$database`.* TO '$database'@'%'");

    $isTest = $purpose !== 'dev';
    $environment = [
        'APP_NAME' => 'MRKTFlow', 'APP_ENV' => $isTest ? 'testing' : 'local',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_DEBUG' => 'false',
        'APP_URL' => 'http://127.0.0.1:8011', 'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => '33306', 'DB_SOCKET' => '',
        'DB_DATABASE' => $database, 'DB_USERNAME' => $database, 'DB_PASSWORD' => $secret,
        'CACHE_STORE' => $purpose === 'test' ? 'array' : 'database',
        'SESSION_DRIVER' => $purpose === 'test' ? 'array' : 'database',
        'SESSION_ENCRYPT' => 'true', 'SESSION_SECURE_COOKIE' => 'false',
        'QUEUE_CONNECTION' => $isTest ? 'sync' : 'database', 'MAIL_MAILER' => 'array',
        'FILESYSTEM_DISK' => 'local', 'LOG_CHANNEL' => 'single', 'LOG_LEVEL' => 'warning',
    ];
    $lines = [];
    foreach ($environment as $key => $value) {
        $lines[] = $key.'='.$value;
    }
    $path = $root.'/'.match ($purpose) {
        'dev' => '.env', 'test' => '.env.testing', 'browser' => '.env.browser',
    };
    if (file_put_contents($path, implode("\n", $lines)."\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not create the private CI environment.');
    }
}

echo "Disposable database accounts and private environments prepared.\n";
