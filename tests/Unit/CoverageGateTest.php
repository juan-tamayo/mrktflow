<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** Synthetic Clover reports test the gate; these are not measured application coverage. */
function coverageGateFixture(string $targetDomain, int $covered): string
{
    $xml = '<coverage><project>';
    foreach (['Pricing', 'Catalog', 'CheckoutOrders', 'Fulfillment'] as $domain) {
        $directory = dirname(__DIR__, 2).'/app/Domain/'.$domain;
        $files = [];
        if (is_dir($directory)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        if ($files === []) {
            $files[] = '/project/app/Domain/'.$domain.'/Fixture.php';
        }
        foreach ($files as $file) {
            $xml .= '<file name="'.htmlspecialchars($file, ENT_XML1 | ENT_QUOTES).'"><metrics statements="100" coveredstatements="'.($domain === $targetDomain ? $covered : 100).'"/></file>';
        }
    }

    return $xml.'</project></coverage>';
}

it('CoverageGate rejects missing reports', function (): void {
    $process = new Process([PHP_BINARY, 'scripts/coverage-gate.php', 'missing-report.xml'], dirname(__DIR__, 2));
    $process->run();
    expect($process->getExitCode())->toBe(1);
});

it('CoverageGate enforces coverage on each critical domain', function (int $covered, int $expected, string $domain): void {
    $path = tempnam(sys_get_temp_dir(), 'mrktflow-coverage-');
    try {
        file_put_contents($path, coverageGateFixture($domain, $covered));
        $process = new Process([PHP_BINARY, 'scripts/coverage-gate.php', $path], dirname(__DIR__, 2));
        $process->run();
        expect($process->getExitCode())->toBe($expected);
    } finally {
        unlink($path);
    }
})->with(['below threshold' => [89, 1], 'threshold' => [90, 0], 'fully covered' => [100, 0]])
    ->with(['Pricing', 'Catalog', 'CheckoutOrders', 'Fulfillment']);

it('CoverageGate rejects a report that omits implemented payment files', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'mrktflow-coverage-');
    try {
        file_put_contents($path, '<coverage><project/></coverage>');
        $process = new Process([PHP_BINARY, 'scripts/coverage-gate.php', $path], dirname(__DIR__, 2));
        $process->run();
        expect($process->getExitCode())->toBe(1);
        expect($process->getErrorOutput())->toContain('CheckoutOrders has a PHP file missing from coverage.');
    } finally {
        unlink($path);
    }
});
