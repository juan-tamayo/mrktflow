<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

final class ExportCsv
{
    public static function isFormulaRisk(string $value): bool
    {
        if (str_contains($value, "\0")) {
            return true;
        }
        $trimmed = ltrim($value, " \t");
        if ($trimmed === '') {
            return false;
        }

        return in_array($trimmed[0], ['=', '+', '-', '@', "\t", "\r"], true);
    }

    public static function neutralize(string $value): string
    {
        if (self::isFormulaRisk($value)) {
            return "'".$value;
        }

        return $value;
    }

    /** @param list<string> $fields */
    public static function line(array $fields): string
    {
        $escaped = [];
        foreach ($fields as $field) {
            $escaped[] = '"'.str_replace('"', '""', self::neutralize($field)).'"';
        }

        return implode(',', $escaped);
    }
}
