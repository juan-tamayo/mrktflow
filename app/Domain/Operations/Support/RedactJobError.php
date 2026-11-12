<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

final class RedactJobError
{
    public function code(?string $exception): string
    {
        $first = is_string($exception) ? (strtok($exception, "\n") ?: '') : '';
        if (preg_match('/^([A-Za-z0-9_\\\\]+)/', $first, $matches) !== 1) {
            return 'job_failed';
        }
        $class = str_replace('\\', '/', $matches[1]);
        $base = basename($class);

        return $base !== '' ? substr($base, 0, 64) : 'job_failed';
    }
}
