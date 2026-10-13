<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class StaffClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return now()->toDateTimeImmutable();
    }
}
