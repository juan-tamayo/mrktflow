<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

final class DiskUsage
{
    /**
     * @return array{path: string, free_bytes: int, total_bytes: int, used_percent: int}
     */
    public function measure(string $path): array
    {
        $fixture = config('operations.health.disk_fixture');
        if (is_array($fixture) && isset($fixture['free_bytes'], $fixture['total_bytes'], $fixture['used_percent'])) {
            return [
                'path' => is_string($fixture['path'] ?? null) ? $fixture['path'] : $path,
                'free_bytes' => (int) $fixture['free_bytes'],
                'total_bytes' => (int) $fixture['total_bytes'],
                'used_percent' => (int) $fixture['used_percent'],
            ];
        }
        $free = disk_free_space($path);
        $total = disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return ['path' => $path, 'free_bytes' => 0, 'total_bytes' => 0, 'used_percent' => 100];
        }
        $freeBytes = (int) $free;
        $totalBytes = (int) $total;
        $usedPercent = (int) floor((($totalBytes - $freeBytes) / $totalBytes) * 100);

        return [
            'path' => $path,
            'free_bytes' => $freeBytes,
            'total_bytes' => $totalBytes,
            'used_percent' => max(0, min(100, $usedPercent)),
        ];
    }
}
