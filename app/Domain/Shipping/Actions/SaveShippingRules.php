<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Platform\Support\SetupChoices;
use App\Domain\Shipping\Rules\MatchDestination;
use App\Domain\Shipping\Support\StoreMoneySettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveShippingRules
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{revision: int, origin: array<string, mixed>}
     */
    public function save(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('shipping.manage')) {
            throw new AuthorizationException;
        }
        foreach (['region_code'] as $empty) {
            if (($input['origin'][$empty] ?? null) === '') {
                $input['origin'][$empty] = null;
            }
        }
        $money = app(StoreMoneySettings::class)->current();
        $countries = array_column(app(SetupChoices::class)->countries(), 'code');
        $data = Validator::make(['rules' => $input], [
            'rules' => ['required', 'array:origin,classes,zones'],
            'rules.origin' => ['required', 'array:country_code,region_code,postal_code,city,street,version'],
            'rules.origin.country_code' => ['required', 'string', Rule::in($countries)],
            'rules.origin.region_code' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9-]{1,16}$/'],
            'rules.origin.postal_code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9 -]{1,16}$/'],
            'rules.origin.city' => ['required', 'string', 'max:80'],
            'rules.origin.street' => ['required', 'string', 'max:200'],
            'rules.origin.version' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'rules.classes' => ['required', 'array', 'min:1', 'max:32'],
            'rules.classes.*.key' => ['required', 'string', 'distinct:strict', 'regex:/^[a-z][a-z0-9_]{1,30}$/'],
            'rules.classes.*.name' => ['required', 'string', 'max:80'],
            'rules.zones' => ['required', 'array', 'max:40'],
            'rules.zones.*.id' => ['nullable', 'uuid'],
            'rules.zones.*.name' => ['required', 'string', 'max:80'],
            'rules.zones.*.priority' => ['required', 'integer', 'min:1', 'max:1000'],
            'rules.zones.*.status' => ['required', Rule::in(['draft', 'active'])],
            'rules.zones.*.matches' => ['required', 'array', 'min:1', 'max:40'],
            'rules.zones.*.matches.*.country_code' => ['required', 'string', Rule::in($money['countries'])],
            'rules.zones.*.matches.*.region_code' => ['nullable', 'string', 'max:16'],
            'rules.zones.*.matches.*.postal_pattern' => ['nullable', 'string', 'max:32'],
            'rules.zones.*.methods' => ['required', 'array', 'min:1', 'max:20'],
            'rules.zones.*.methods.*.label' => ['required', 'string', 'max:80'],
            'rules.zones.*.methods.*.type' => ['required', Rule::in(['flat', 'free', 'table', 'pickup'])],
            'rules.zones.*.methods.*.amount' => ['nullable', 'integer', 'min:0', 'max:'.StoreMoneySettings::MAX_SAFE],
            'rules.zones.*.methods.*.free_min_subtotal' => ['nullable', 'integer', 'min:0', 'max:'.StoreMoneySettings::MAX_SAFE],
            'rules.zones.*.methods.*.table_basis' => ['nullable', Rule::in(['subtotal', 'weight'])],
            'rules.zones.*.methods.*.table_rows' => ['sometimes', 'array', 'max:40'],
            'rules.zones.*.methods.*.table_rows.*.min' => ['required_with:rules.zones.*.methods.*.table_rows', 'integer', 'min:0', 'max:'.StoreMoneySettings::MAX_SAFE],
            'rules.zones.*.methods.*.table_rows.*.max' => ['nullable', 'integer', 'min:1', 'max:'.StoreMoneySettings::MAX_SAFE],
            'rules.zones.*.methods.*.table_rows.*.amount' => ['required_with:rules.zones.*.methods.*.table_rows', 'integer', 'min:0', 'max:'.StoreMoneySettings::MAX_SAFE],
            'rules.zones.*.methods.*.included_class_keys' => ['sometimes', 'array', 'max:32'],
            'rules.zones.*.methods.*.included_class_keys.*' => ['string'],
            'rules.zones.*.methods.*.excluded_class_keys' => ['sometimes', 'array', 'max:32'],
            'rules.zones.*.methods.*.excluded_class_keys.*' => ['string'],
            'rules.zones.*.methods.*.pickup' => ['nullable', 'array'],
            'rules.zones.*.methods.*.pickup.name' => ['required_with:rules.zones.*.methods.*.pickup', 'string', 'max:80'],
            'rules.zones.*.methods.*.pickup.street' => ['required_with:rules.zones.*.methods.*.pickup', 'string', 'max:200'],
            'rules.zones.*.methods.*.pickup.city' => ['required_with:rules.zones.*.methods.*.pickup', 'string', 'max:80'],
            'rules.zones.*.methods.*.pickup.region_code' => ['nullable', 'string', 'max:16'],
            'rules.zones.*.methods.*.pickup.postal_code' => ['required_with:rules.zones.*.methods.*.pickup', 'string', 'max:16'],
            'rules.zones.*.methods.*.pickup.country_code' => ['required_with:rules.zones.*.methods.*.pickup', 'string', Rule::in($countries)],
            'rules.zones.*.methods.*.pickup.instructions' => ['nullable', 'string', 'max:500'],
        ])->validate()['rules'];
        $matcher = app(MatchDestination::class);
        $classKeys = [];
        foreach ($data['classes'] as $class) {
            $classKeys[] = (string) $class['key'];
        }
        if (! in_array('general', $classKeys, true)) {
            throw ValidationException::withMessages(['rules.classes' => 'The built-in general shipping class is required.']);
        }
        $zones = [];
        foreach ($data['zones'] as $zone) {
            $matches = [];
            foreach ($zone['matches'] as $match) {
                $region = $this->nullableString($match['region_code'] ?? null);
                $pattern = $this->nullableString($match['postal_pattern'] ?? null);
                if ($pattern !== null) {
                    $pattern = $matcher->normalizePattern($pattern);
                }
                if ($region !== null && preg_match('/^[A-Za-z0-9-]{1,16}$/', $region) !== 1) {
                    throw ValidationException::withMessages(['rules.zones' => 'Region codes use letters, numbers, or a hyphen.']);
                }
                $matches[] = [
                    'country_code' => strtoupper((string) $match['country_code']),
                    'region_code' => $region === null ? null : strtoupper($region),
                    'postal_pattern' => $pattern,
                ];
            }
            $methods = [];
            foreach ($zone['methods'] as $method) {
                $methods[] = $this->validatedMethod($method, $classKeys);
            }
            $zones[] = [
                'id' => isset($zone['id']) && is_string($zone['id']) ? $zone['id'] : (string) Str::uuid(),
                'name' => (string) $zone['name'],
                'priority' => (int) $zone['priority'],
                'status' => (string) $zone['status'],
                'matches' => $matches,
                'methods' => $methods,
            ];
        }
        $matcher->assertNoSamePriorityOverlap($zones);

        return DB::transaction(function () use ($actor, $data, $zones, $money): array {
            $now = now();
            $existing = DB::table('shipping_origins')->where('id', 1)->lockForUpdate()->first();
            $version = $data['origin']['version'] ?? null;
            if ($existing === null) {
                abort_if(is_numeric($version), 409, 'This shipping configuration changed. Reload before saving.');
                $revision = 1;
            } else {
                abort_unless(is_numeric($version) && (int) $existing->revision === (int) $version, 409, 'This shipping configuration changed. Reload before saving.');
                abort_if((int) $existing->revision >= 4294967295, 409);
                $revision = (int) $existing->revision + 1;
            }
            $origin = [
                'country_code' => strtoupper((string) $data['origin']['country_code']),
                'region_code' => $this->nullableUpper($data['origin']['region_code'] ?? null),
                'postal_code' => strtoupper(preg_replace('/\s+/', '', (string) $data['origin']['postal_code']) ?? ''),
                'city' => (string) $data['origin']['city'],
                'street' => (string) $data['origin']['street'],
                'revision' => $revision,
                'updated_at' => $now,
            ];
            if ($existing === null) {
                DB::table('shipping_origins')->insert($origin + ['id' => 1, 'created_at' => $now]);
            } else {
                DB::table('shipping_origins')->where('id', 1)->update($origin);
            }
            DB::table('shipping_zones')->delete();
            $this->syncClasses($data['classes'], $now);
            foreach ($zones as $zone) {
                DB::table('shipping_zones')->insert([
                    'id' => $zone['id'],
                    'name' => $zone['name'],
                    'priority' => $zone['priority'],
                    'status' => $zone['status'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                foreach ($zone['matches'] as $match) {
                    DB::table('shipping_zone_matches')->insert([
                        'id' => (string) Str::uuid(),
                        'zone_id' => $zone['id'],
                        'country_code' => $match['country_code'],
                        'region_code' => $match['region_code'],
                        'postal_pattern' => $match['postal_pattern'],
                    ]);
                }
                foreach ($zone['methods'] as $index => $method) {
                    $methodId = (string) Str::uuid();
                    DB::table('shipping_methods')->insert([
                        'id' => $methodId,
                        'zone_id' => $zone['id'],
                        'label' => $method['label'],
                        'type' => $method['type'],
                        'amount' => $method['amount'],
                        'currency' => $money['currency'],
                        'exponent' => $money['exponent'],
                        'free_min_subtotal' => $method['free_min_subtotal'],
                        'table_basis' => $method['table_basis'],
                        'pickup_name' => $method['pickup']['name'] ?? null,
                        'pickup_street' => $method['pickup']['street'] ?? null,
                        'pickup_city' => $method['pickup']['city'] ?? null,
                        'pickup_region_code' => $method['pickup']['region_code'] ?? null,
                        'pickup_postal_code' => $method['pickup']['postal_code'] ?? null,
                        'pickup_country_code' => $method['pickup']['country_code'] ?? null,
                        'pickup_instructions' => $method['pickup']['instructions'] ?? null,
                        'sort_order' => $index,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    foreach ($method['table_rows'] as $rowIndex => $row) {
                        DB::table('shipping_rate_rules')->insert([
                            'id' => (string) Str::uuid(),
                            'method_id' => $methodId,
                            'min_value' => $row['min'],
                            'max_value' => $row['max'],
                            'amount' => $row['amount'],
                            'sort_order' => $rowIndex,
                        ]);
                    }
                    $this->syncMethodClasses($methodId, $method['included_class_keys'], 'include');
                    $this->syncMethodClasses($methodId, $method['excluded_class_keys'], 'exclude');
                }
            }
            app(RecordEvent::class)->record('platform.audit.recorded', 'shipping:rules:'.$revision, [
                'action' => 'shipping.rules.saved', 'subject_type' => 'shipping_origin', 'subject_id' => '1',
                'actor_type' => 'staff', 'actor_id' => $actor->id, 'metadata' => ['version' => $revision],
            ]);

            return [
                'revision' => $revision,
                'origin' => [
                    'country_code' => $origin['country_code'],
                    'region_code' => $origin['region_code'],
                    'postal_code' => $origin['postal_code'],
                    'city' => $origin['city'],
                    'street' => $origin['street'],
                    'version' => $revision,
                ],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $method
     * @param  list<string>  $classKeys
     * @return array<string, mixed>
     */
    private function validatedMethod(array $method, array $classKeys): array
    {
        $type = (string) $method['type'];
        $amount = is_numeric($method['amount'] ?? null) ? (int) $method['amount'] : null;
        $minSubtotal = is_numeric($method['free_min_subtotal'] ?? null) ? (int) $method['free_min_subtotal'] : null;
        $basis = is_string($method['table_basis'] ?? null) ? (string) $method['table_basis'] : null;
        $rows = isset($method['table_rows']) && is_array($method['table_rows']) ? array_values($method['table_rows']) : [];
        $pickup = is_array($method['pickup'] ?? null) ? $method['pickup'] : null;
        if ($type === 'flat' && ($amount === null || $amount < 0)) {
            throw ValidationException::withMessages(['rules.zones' => 'A flat rate needs an amount in the store currency.']);
        }
        if ($type === 'free' && $minSubtotal === null) {
            throw ValidationException::withMessages(['rules.zones' => 'Free shipping needs a physical merchandise threshold.']);
        }
        if ($type === 'table') {
            if ($basis === null) {
                throw ValidationException::withMessages(['rules.zones' => 'A table rate needs a subtotal or weight basis.']);
            }
            $rows = $this->validatedRows($rows);
        } else {
            $rows = [];
            $basis = null;
        }
        if ($type === 'pickup') {
            if ($pickup === null) {
                throw ValidationException::withMessages(['rules.zones' => 'Pickup needs a location for tax destination later.']);
            }
            $pickup = [
                'name' => (string) $pickup['name'],
                'street' => (string) $pickup['street'],
                'city' => (string) $pickup['city'],
                'region_code' => $this->nullableUpper($pickup['region_code'] ?? null),
                'postal_code' => strtoupper(preg_replace('/\s+/', '', (string) $pickup['postal_code']) ?? ''),
                'country_code' => strtoupper((string) $pickup['country_code']),
                'instructions' => $this->nullableString($pickup['instructions'] ?? null),
            ];
            $amount = 0;
        } else {
            $pickup = null;
        }
        if ($type === 'free') {
            $amount = 0;
        }
        $included = $this->classList(isset($method['included_class_keys']) && is_array($method['included_class_keys']) ? array_values($method['included_class_keys']) : [], $classKeys, 'included_class_keys');
        $excluded = $this->classList(isset($method['excluded_class_keys']) && is_array($method['excluded_class_keys']) ? array_values($method['excluded_class_keys']) : [], $classKeys, 'excluded_class_keys');
        if (array_intersect($included, $excluded) !== []) {
            throw ValidationException::withMessages(['rules.zones' => 'A method cannot include and exclude the same shipping class.']);
        }

        return [
            'label' => (string) $method['label'],
            'type' => $type,
            'amount' => $type === 'flat' ? $amount : ($type === 'free' || $type === 'pickup' ? 0 : null),
            'free_min_subtotal' => $type === 'free' ? $minSubtotal : null,
            'table_basis' => $basis,
            'table_rows' => $rows,
            'included_class_keys' => $included,
            'excluded_class_keys' => $excluded,
            'pickup' => $pickup,
        ];
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array{min: int, max: int|null, amount: int}>
     */
    private function validatedRows(array $rows): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['rules.zones' => 'A table rate needs at least one row.']);
        }
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_numeric($row['min'] ?? null) || ! is_numeric($row['amount'] ?? null)) {
                throw ValidationException::withMessages(['rules.zones' => 'Table rows need integer bounds and amounts.']);
            }
            $min = (int) $row['min'];
            $max = is_numeric($row['max'] ?? null) ? (int) $row['max'] : null;
            if ($max !== null && $max <= $min) {
                throw ValidationException::withMessages(['rules.zones' => 'Each table row must end after it starts.']);
            }
            $normalized[] = ['min' => $min, 'max' => $max, 'amount' => (int) $row['amount']];
        }
        usort($normalized, static fn (array $a, array $b): int => $a['min'] <=> $b['min']);
        $previousEnd = null;
        foreach ($normalized as $row) {
            if ($previousEnd !== null && $row['min'] < $previousEnd) {
                throw ValidationException::withMessages(['rules.zones' => 'Table rows cannot overlap.']);
            }
            $previousEnd = $row['max'] ?? StoreMoneySettings::MAX_SAFE + 1;
        }

        return $normalized;
    }

    /**
     * @param  list<mixed>  $keys
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function classList(array $keys, array $allowed, string $field): array
    {
        $list = [];
        foreach ($keys as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages(['rules.'.$field => 'Choose shipping classes that exist.']);
            }
            $list[] = $key;
        }

        return array_values(array_unique($list));
    }

    /** @param list<array{key: string, name: string}> $classes */
    private function syncClasses(array $classes, mixed $now): void
    {
        $kept = [];
        foreach ($classes as $class) {
            $key = (string) $class['key'];
            $kept[] = $key;
            $existing = DB::table('shipping_classes')->where('class_key', $key)->first();
            $row = ['name' => (string) $class['name'], 'updated_at' => $now];
            if ($existing === null) {
                DB::table('shipping_classes')->insert($row + [
                    'class_key' => $key, 'is_builtin' => $key === 'general', 'created_at' => $now,
                ]);
            } else {
                DB::table('shipping_classes')->where('class_key', $key)->update($row);
            }
        }
        $removals = DB::table('shipping_classes')->whereNotIn('class_key', $kept)->get();
        foreach ($removals as $removal) {
            $key = $this->string($removal, 'class_key');
            abort_if((bool) $this->int($removal, 'is_builtin'), 422);
            if (DB::table('product_variants')->where('shipping_class_key', $key)->exists()) {
                throw ValidationException::withMessages(['rules.classes' => 'A shipping class used by a product cannot be removed.']);
            }
            DB::table('shipping_classes')->where('class_key', $key)->delete();
        }
    }

    /** @param list<string> $keys */
    private function syncMethodClasses(string $methodId, array $keys, string $mode): void
    {
        foreach ($keys as $key) {
            DB::table('shipping_method_classes')->insert([
                'id' => (string) Str::uuid(),
                'method_id' => $methodId,
                'class_key' => $key,
                'mode' => $mode,
            ]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function nullableUpper(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : strtoupper($value);
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
