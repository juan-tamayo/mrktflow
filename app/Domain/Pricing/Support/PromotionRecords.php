<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class PromotionRecords
{
    /** @param  object|array<string, mixed>  $row */
    public function string(object|array $row, string $key): string
    {
        $value = is_array($row) ? ($row[$key] ?? null) : (get_object_vars($row)[$key] ?? null);

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param  object|array<string, mixed>  $row */
    public function int(object|array $row, string $key): int
    {
        $value = is_array($row) ? ($row[$key] ?? null) : (get_object_vars($row)[$key] ?? null);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function nullable(object $row, string $key): ?string
    {
        $value = $this->string($row, $key);

        return $value === '' ? null : $value;
    }

    public function nullableInt(object $row, string $key): ?int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @return list<string> */
    public function idList(object $row, string $key): array
    {
        $value = get_object_vars($row)[$key] ?? null;
        if (is_array($value)) {
            return $this->ids($value);
        }
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Promotion JSON is invalid.');
        }

        return $this->ids($decoded);
    }

    /**
     * @param  array<mixed>  $value
     * @return list<string>
     */
    public function ids(array $value): array
    {
        $ids = [];
        foreach ($value as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function instant(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    public function iso(?DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public function time(object $row, string $key): ?string
    {
        return $this->iso($this->instant($this->nullable($row, $key)));
    }

    public function sqlTime(?DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
