<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('CoverageGate rejects missing reports', function (): void {
    $process = new Process([PHP_BINARY, 'scripts/coverage-gate.php', 'missing-report.xml'], dirname(__DIR__, 2));
    $process->run();
    expect($process->getExitCode())->toBe(1);
});

it('CoverageGate enforces coverage on each critical domain', function (int $covered, int $expected): void {
    $path = tempnam(sys_get_temp_dir(), 'mrktflow-coverage-');
    try {
        file_put_contents($path, '<coverage><project><file name="/project/app/Domain/Pricing/Money.php"><metrics statements="100" coveredstatements="'.$covered.'"/></file></project></coverage>');
        $process = new Process([PHP_BINARY, 'scripts/coverage-gate.php', $path], dirname(__DIR__, 2));
        $process->run();
        expect($process->getExitCode())->toBe($expected);
    } finally {
        unlink($path);
    }
})->with(['below threshold' => [89, 1], 'threshold' => [90, 0], 'fully covered' => [100, 0]]);
