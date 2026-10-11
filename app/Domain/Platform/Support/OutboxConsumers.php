<?php

declare(strict_types=1);

namespace App\Domain\Platform\Support;

use App\Domain\Platform\Contracts\OutboxConsumer;
use InvalidArgumentException;

final class OutboxConsumers
{
    /** @return array<string, class-string<OutboxConsumer>> */
    public function forEvent(string $type): array
    {
        $bindings = config()->array('outbox.consumers')[$type] ?? null;
        if (! is_array($bindings) || $bindings === [] || count($bindings) > 20) {
            throw new InvalidArgumentException('The event has no supported consumer bindings.');
        }
        $result = [];
        foreach ($bindings as $id => $class) {
            if (! is_string($id) || ! preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $id)
                || ! is_string($class) || ! is_subclass_of($class, OutboxConsumer::class)) {
                throw new InvalidArgumentException('Invalid outbox consumer binding.');
            }
            $result[$id] = $class;
        }

        return $result;
    }
}
