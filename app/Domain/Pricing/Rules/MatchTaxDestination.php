<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Rules;

use App\Domain\Pricing\Data\TaxDestination;
use App\Domain\Pricing\Exceptions\PricingTaxRuleConflict;
use Illuminate\Validation\ValidationException;

final class MatchTaxDestination
{
    /**
     * @param  array{country_code: string, region_code: ?string, postal_pattern: ?string}  $match
     */
    public function matches(array $match, TaxDestination $destination): bool
    {
        if (strtoupper($match['country_code']) !== $destination->countryCode) {
            return false;
        }
        $region = $match['region_code'];
        if (is_string($region) && $region !== '' && strtoupper($region) !== $destination->regionCode) {
            return false;
        }
        $pattern = $match['postal_pattern'];
        if (! is_string($pattern) || $pattern === '') {
            return true;
        }
        if ($destination->postalCode === null) {
            return false;
        }

        return $this->postalMatches($this->normalizePattern($pattern), $destination->postalCode);
    }

    /**
     * @param  array{country_code: string, region_code: ?string, postal_pattern: ?string}  $match
     */
    public function specificity(array $match): int
    {
        if (is_string($match['postal_pattern'] ?? null) && $match['postal_pattern'] !== '') {
            return 3;
        }
        if (is_string($match['region_code'] ?? null) && $match['region_code'] !== '') {
            return 2;
        }

        return 1;
    }

    /**
     * @param  array{country_code: string, region_code: ?string, postal_pattern: ?string}  $left
     * @param  array{country_code: string, region_code: ?string, postal_pattern: ?string}  $right
     */
    public function overlaps(array $left, array $right): bool
    {
        if (strtoupper($left['country_code']) !== strtoupper($right['country_code'])) {
            return false;
        }
        $leftRegion = $this->nullableUpper($left['region_code'] ?? null);
        $rightRegion = $this->nullableUpper($right['region_code'] ?? null);
        if ($leftRegion !== null && $rightRegion !== null && $leftRegion !== $rightRegion) {
            return false;
        }

        return $this->postalPatternsOverlap(
            $this->nullablePattern($left['postal_pattern'] ?? null),
            $this->nullablePattern($right['postal_pattern'] ?? null),
        );
    }

    /**
     * @param  list<array{name: string, class_key: string, status: string, match: array{country_code: string, region_code: ?string, postal_pattern: ?string}}>  $rules
     */
    public function assertNoEqualSpecificityOverlap(array $rules): void
    {
        $active = array_values(array_filter($rules, static fn (array $rule): bool => $rule['status'] === 'active'));
        $count = count($active);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($active[$i]['class_key'] !== $active[$j]['class_key']) {
                    continue;
                }
                if ($this->specificity($active[$i]['match']) !== $this->specificity($active[$j]['match'])) {
                    continue;
                }
                if ($this->overlaps($active[$i]['match'], $active[$j]['match'])) {
                    throw new PricingTaxRuleConflict(
                        'Tax rules "'.$active[$i]['name'].'" and "'.$active[$j]['name'].'" share specificity and overlapping destinations.',
                    );
                }
            }
        }
    }

    public function normalizePattern(string $pattern): string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $pattern) ?? '');
        if (preg_match('/^(?:[A-Z0-9]{1,16}|[A-Z0-9]{1,15}\*|[0-9]{1,10}-[0-9]{1,10})$/', $normalized) !== 1) {
            throw ValidationException::withMessages(['rules.rules' => 'Postal patterns must be an exact code, a prefix ending in *, or a numeric range.']);
        }

        return $normalized;
    }

    private function postalMatches(string $pattern, string $postal): bool
    {
        if (str_ends_with($pattern, '*')) {
            $prefix = substr($pattern, 0, -1);

            return $prefix !== '' && str_starts_with($postal, $prefix);
        }
        if (str_contains($pattern, '-')) {
            [$start, $end] = explode('-', $pattern, 2);
            if (preg_match('/^[0-9]+$/', $postal) !== 1) {
                return false;
            }
            $value = (int) $postal;

            return $value >= (int) $start && $value <= (int) $end;
        }

        return $pattern === $postal;
    }

    private function postalPatternsOverlap(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return true;
        }
        if ($left === $right) {
            return true;
        }
        if (str_ends_with($left, '*') && str_ends_with($right, '*')) {
            $leftPrefix = substr($left, 0, -1);
            $rightPrefix = substr($right, 0, -1);

            return str_starts_with($leftPrefix, $rightPrefix) || str_starts_with($rightPrefix, $leftPrefix);
        }
        if (str_ends_with($left, '*')) {
            return $this->prefixOverlaps($left, $right);
        }
        if (str_ends_with($right, '*')) {
            return $this->prefixOverlaps($right, $left);
        }
        if (str_contains($left, '-') && str_contains($right, '-')) {
            [$aStart, $aEnd] = array_map(intval(...), explode('-', $left, 2));
            [$bStart, $bEnd] = array_map(intval(...), explode('-', $right, 2));

            return $aStart <= $bEnd && $bStart <= $aEnd;
        }
        if (str_contains($left, '-')) {
            return $this->postalMatches($left, $right);
        }
        if (str_contains($right, '-')) {
            return $this->postalMatches($right, $left);
        }

        return false;
    }

    private function prefixOverlaps(string $prefixPattern, string $other): bool
    {
        $prefix = substr($prefixPattern, 0, -1);
        if ($prefix === '') {
            return true;
        }
        if (! str_contains($other, '-') && ! str_ends_with($other, '*')) {
            return str_starts_with($other, $prefix);
        }
        if (str_contains($other, '-')) {
            if (preg_match('/^[0-9]+$/', $prefix) !== 1) {
                return true;
            }
            [$start, $end] = array_map(intval(...), explode('-', $other, 2));
            $width = 16 - strlen($prefix);
            $low = (int) str_pad($prefix, strlen($prefix) + max($width, 0), '0');
            $high = (int) str_pad($prefix, strlen($prefix) + max($width, 0), '9');
            if ($width <= 0) {
                $low = (int) $prefix;
                $high = (int) $prefix;
            }

            return $low <= $end && $start <= $high;
        }

        return $this->postalPatternsOverlap($prefixPattern, $other);
    }

    private function nullableUpper(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return strtoupper($value);
    }

    private function nullablePattern(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $this->normalizePattern($value);
    }
}
