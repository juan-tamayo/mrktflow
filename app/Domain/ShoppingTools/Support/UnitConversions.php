<?php

declare(strict_types=1);

namespace App\Domain\ShoppingTools\Support;

/**
 * The typed unit registry for comparison normalization (SHOP-05).
 *
 * Attribute definitions carry a free-form unit string, but only the
 * units registered here have a known dimension and an exact factor to
 * that dimension's canonical base unit (mass → grams, volume →
 * millilitres, length → millimetres). Conversion is arithmetic on the
 * typed decimal value, never string manipulation: an unknown unit or
 * a cross-dimension request returns null so the caller degrades to the
 * value's own unit instead of comparing numbers that do not share a
 * dimension.
 */
final class UnitConversions
{
    /** @var array<string, array{base: string, factors: array<string, string>}> */
    private const DIMENSIONS = [
        'mass' => [
            'base' => 'g',
            'factors' => ['mg' => '0.001', 'g' => '1', 'kg' => '1000', 'oz' => '28.349523125', 'lb' => '453.59237'],
        ],
        'volume' => [
            'base' => 'mL',
            'factors' => ['mL' => '1', 'L' => '1000'],
        ],
        'length' => [
            'base' => 'mm',
            'factors' => ['mm' => '1', 'cm' => '10', 'm' => '1000', 'in' => '25.4', 'ft' => '304.8'],
        ],
    ];

    public function dimension(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }
        foreach (self::DIMENSIONS as $dimension => $definition) {
            if (isset($definition['factors'][$unit])) {
                return $dimension;
            }
        }

        return null;
    }

    public function canonicalUnit(string $dimension): ?string
    {
        return self::DIMENSIONS[$dimension]['base'] ?? null;
    }

    /**
     * Convert a typed decimal value between two units of one
     * dimension. Returns the canonical decimal string, or null when
     * either unit is unknown or the units live in different
     * dimensions.
     */
    public function convert(string $value, string $from, string $to): ?string
    {
        $dimension = $this->dimension($from);
        if ($dimension === null || $dimension !== $this->dimension($to)) {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $factors = self::DIMENSIONS[$dimension]['factors'];
        $base = (float) $value * (float) $factors[$from];
        $converted = $base / (float) $factors[$to];

        return self::format($converted);
    }

    /**
     * Express a value in its dimension's canonical base unit.
     *
     * @return array{value: string, unit: string}|null null when the
     *                                                 unit has no known dimension
     */
    public function normalize(string $value, string $unit): ?array
    {
        $dimension = $this->dimension($unit);
        if ($dimension === null) {
            return null;
        }
        $base = self::DIMENSIONS[$dimension]['base'];
        $converted = $this->convert($value, $unit, $base);
        if ($converted === null) {
            return null;
        }

        return ['value' => $converted, 'unit' => $base];
    }

    /** Canonical decimal string at storage precision (6 dp, trimmed). */
    public static function format(float $value): string
    {
        $formatted = number_format(round($value, 6), 6, '.', '');
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }
}
