<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use Illuminate\Support\Facades\DB;

final class ReadTaxRules
{
    /**
     * @return array{
     *     price_mode: string,
     *     shipping_tax_class_key: string,
     *     version: int|null,
     *     classes: list<array{key: string, name: string}>,
     *     rules: list<array<string, mixed>>
     * }
     */
    public function staffRules(): array
    {
        $settings = DB::table('pricing_settings')->where('id', 1)->first();
        $classes = [];
        foreach (DB::table('tax_classes')->orderBy('class_key')->get() as $class) {
            $classes[] = ['key' => $this->string($class, 'class_key'), 'name' => $this->string($class, 'name')];
        }
        $rules = [];
        foreach (DB::table('tax_rules')->orderBy('name')->orderBy('id')->get() as $rule) {
            $rules[] = [
                'id' => $this->string($rule, 'id'),
                'name' => $this->string($rule, 'name'),
                'class_key' => $this->string($rule, 'class_key'),
                'status' => $this->string($rule, 'status'),
                'rate_numerator' => $this->int($rule, 'rate_numerator'),
                'rate_denominator' => $this->int($rule, 'rate_denominator'),
                'match' => [
                    'country_code' => $this->string($rule, 'country_code'),
                    'region_code' => $this->nullableString($rule, 'region_code'),
                    'postal_pattern' => $this->nullableString($rule, 'postal_pattern'),
                ],
            ];
        }

        return [
            'price_mode' => $settings === null ? 'exclusive' : $this->string($settings, 'price_mode'),
            'shipping_tax_class_key' => $settings === null ? 'standard' : $this->string($settings, 'shipping_tax_class_key'),
            'version' => $settings === null ? null : $this->int($settings, 'revision'),
            'classes' => $classes,
            'rules' => $rules,
        ];
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableString(object $row, string $key): ?string
    {
        $value = get_object_vars($row)[$key] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
