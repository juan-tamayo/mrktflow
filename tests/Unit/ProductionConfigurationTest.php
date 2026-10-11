<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('PlatformSecurity disables production debug before providers boot', function (): void {
    $process = new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; $config = require "config/app.php"; exit($config["debug"] ? 1 : 0);'], dirname(__DIR__, 2), [
        'APP_ENV' => 'production', 'APP_DEBUG' => 'true',
    ]);
    $process->run();
    expect($process->getExitCode())->toBe(0);
});
