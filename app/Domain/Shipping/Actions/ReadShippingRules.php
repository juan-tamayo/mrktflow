<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Actions;

use Illuminate\Support\Facades\DB;

final class ReadShippingRules
{
    /** @return array{origin: array<string, mixed>|null, classes: list<array{key: string, name: string}>, zones: list<array<string, mixed>>} */
    public function staffRules(): array
    {
        $originRow = DB::table('shipping_origins')->where('id', 1)->first();
        $classes = [];
        foreach (DB::table('shipping_classes')->orderBy('class_key')->get() as $class) {
            $classes[] = ['key' => $this->string($class, 'class_key'), 'name' => $this->string($class, 'name')];
        }
        $zones = [];
        foreach (DB::table('shipping_zones')->orderBy('priority')->orderBy('name')->orderBy('id')->get() as $zone) {
            $zoneId = $this->string($zone, 'id');
            $matches = [];
            foreach (DB::table('shipping_zone_matches')->where('zone_id', $zoneId)->orderBy('country_code')->orderBy('region_code')->get() as $match) {
                $matches[] = [
                    'country_code' => $this->string($match, 'country_code'),
                    'region_code' => $this->nullableString($match, 'region_code'),
                    'postal_pattern' => $this->nullableString($match, 'postal_pattern'),
                ];
            }
            $methods = [];
            foreach (DB::table('shipping_methods')->where('zone_id', $zoneId)->orderBy('sort_order')->orderBy('id')->get() as $method) {
                $methods[] = $this->method($method);
            }
            $zones[] = [
                'id' => $zoneId,
                'name' => $this->string($zone, 'name'),
                'priority' => $this->int($zone, 'priority'),
                'status' => $this->string($zone, 'status'),
                'matches' => $matches,
                'methods' => $methods,
            ];
        }

        return [
            'origin' => $originRow === null ? null : [
                'country_code' => $this->string($originRow, 'country_code'),
                'region_code' => $this->nullableString($originRow, 'region_code'),
                'postal_code' => $this->string($originRow, 'postal_code'),
                'city' => $this->string($originRow, 'city'),
                'street' => $this->string($originRow, 'street'),
                'version' => $this->int($originRow, 'revision'),
            ],
            'classes' => $classes,
            'zones' => $zones,
        ];
    }

    /** @return array<string, mixed> */
    private function method(object $method): array
    {
        $id = $this->string($method, 'id');
        $rows = [];
        foreach (DB::table('shipping_rate_rules')->where('method_id', $id)->orderBy('sort_order')->orderBy('min_value')->get() as $row) {
            $rows[] = [
                'min' => $this->int($row, 'min_value'),
                'max' => $this->nullableInt($row, 'max_value'),
                'amount' => $this->int($row, 'amount'),
            ];
        }
        $included = [];
        $excluded = [];
        foreach (DB::table('shipping_method_classes')->where('method_id', $id)->orderBy('class_key')->get() as $class) {
            if ($this->string($class, 'mode') === 'include') {
                $included[] = $this->string($class, 'class_key');
            } else {
                $excluded[] = $this->string($class, 'class_key');
            }
        }
        $pickup = $this->nullableString($method, 'pickup_name');

        return [
            'id' => $id,
            'label' => $this->string($method, 'label'),
            'type' => $this->string($method, 'type'),
            'amount' => $this->nullableInt($method, 'amount'),
            'free_min_subtotal' => $this->nullableInt($method, 'free_min_subtotal'),
            'table_basis' => $this->nullableString($method, 'table_basis'),
            'table_rows' => $rows,
            'included_class_keys' => $included,
            'excluded_class_keys' => $excluded,
            'pickup' => $pickup === null ? null : [
                'name' => $pickup,
                'street' => $this->string($method, 'pickup_street'),
                'city' => $this->string($method, 'pickup_city'),
                'region_code' => $this->nullableString($method, 'pickup_region_code'),
                'postal_code' => $this->nullableString($method, 'pickup_postal_code'),
                'country_code' => $this->nullableString($method, 'pickup_country_code'),
                'instructions' => $this->nullableString($method, 'pickup_instructions'),
            ],
        ];
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableString(object $row, string $key): ?string
    {
        $value = $this->string($row, $key);

        return $value === '' ? null : $value;
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInt(object $row, string $key): ?int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
