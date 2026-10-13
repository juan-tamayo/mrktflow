<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Platform\Support\SetupChoices;
use App\Domain\Pricing\Rules\MatchTaxDestination;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveTaxRules
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{revision: int, price_mode: string, shipping_tax_class_key: string}
     */
    public function save(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('pricing.manage')) {
            throw new AuthorizationException;
        }
        $countries = $this->salesCountries();
        $data = Validator::make(['rules' => $input], [
            'rules' => ['required', 'array:price_mode,shipping_tax_class_key,version,classes,rules'],
            'rules.price_mode' => ['required', Rule::in(['exclusive', 'inclusive'])],
            'rules.shipping_tax_class_key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,30}$/'],
            'rules.version' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'rules.classes' => ['required', 'array', 'min:1', 'max:32'],
            'rules.classes.*.key' => ['required', 'string', 'distinct:strict', 'regex:/^[a-z][a-z0-9_]{1,30}$/'],
            'rules.classes.*.name' => ['required', 'string', 'max:80'],
            'rules.rules' => ['present', 'array', 'max:80'],
            'rules.rules.*.id' => ['nullable', 'uuid'],
            'rules.rules.*.name' => ['required', 'string', 'max:80'],
            'rules.rules.*.class_key' => ['required', 'string'],
            'rules.rules.*.status' => ['required', Rule::in(['draft', 'active'])],
            'rules.rules.*.rate_numerator' => ['required', 'integer', 'min:0', 'max:10000'],
            'rules.rules.*.rate_denominator' => ['required', 'integer', 'min:1', 'max:10000'],
            'rules.rules.*.match' => ['required', 'array:country_code,region_code,postal_pattern'],
            'rules.rules.*.match.country_code' => ['required', 'string', Rule::in($countries)],
            'rules.rules.*.match.region_code' => ['nullable', 'string', 'max:16'],
            'rules.rules.*.match.postal_pattern' => ['nullable', 'string', 'max:32'],
        ])->validate()['rules'];
        $classKeys = [];
        foreach ($data['classes'] as $class) {
            $classKeys[] = (string) $class['key'];
        }
        if (! in_array('standard', $classKeys, true)) {
            throw ValidationException::withMessages(['rules.classes' => 'The built-in standard tax class is required.']);
        }
        $shippingClass = (string) $data['shipping_tax_class_key'];
        if (! in_array($shippingClass, $classKeys, true)) {
            throw ValidationException::withMessages(['rules.shipping_tax_class_key' => 'Shipping tax must use a configured tax class.']);
        }
        $matcher = app(MatchTaxDestination::class);
        $rules = [];
        foreach ($data['rules'] as $rule) {
            $numerator = (int) $rule['rate_numerator'];
            $denominator = (int) $rule['rate_denominator'];
            if ($numerator > $denominator) {
                throw ValidationException::withMessages(['rules.rules' => 'A tax rate cannot exceed 100 percent.']);
            }
            $classKey = (string) $rule['class_key'];
            if (! in_array($classKey, $classKeys, true)) {
                throw ValidationException::withMessages(['rules.rules' => 'Each tax rule needs a configured class.']);
            }
            $region = $this->nullableString($rule['match']['region_code'] ?? null);
            $pattern = $this->nullableString($rule['match']['postal_pattern'] ?? null);
            if ($pattern !== null) {
                $pattern = $matcher->normalizePattern($pattern);
            }
            if ($region !== null && preg_match('/^[A-Za-z0-9-]{1,16}$/', $region) !== 1) {
                throw ValidationException::withMessages(['rules.rules' => 'Region codes use letters, numbers, or a hyphen.']);
            }
            $rules[] = [
                'id' => isset($rule['id']) && is_string($rule['id']) ? $rule['id'] : (string) Str::uuid(),
                'name' => (string) $rule['name'],
                'class_key' => $classKey,
                'status' => (string) $rule['status'],
                'rate_numerator' => $numerator,
                'rate_denominator' => $denominator,
                'match' => [
                    'country_code' => strtoupper((string) $rule['match']['country_code']),
                    'region_code' => $region === null ? null : strtoupper($region),
                    'postal_pattern' => $pattern,
                ],
            ];
        }
        $matcher->assertNoEqualSpecificityOverlap($rules);

        return DB::transaction(function () use ($actor, $data, $rules, $shippingClass): array {
            $now = now();
            $existing = DB::table('pricing_settings')->where('id', 1)->lockForUpdate()->first();
            $version = $data['version'] ?? null;
            if ($existing === null) {
                abort_if(is_numeric($version), 409, 'This tax configuration changed. Reload before saving.');
                $revision = 1;
            } else {
                abort_unless(is_numeric($version) && (int) $existing->revision === (int) $version, 409, 'This tax configuration changed. Reload before saving.');
                abort_if((int) $existing->revision >= 4294967295, 409);
                $revision = (int) $existing->revision + 1;
            }
            DB::table('tax_rules')->delete();
            $this->syncClasses($data['classes'], $now);
            $mode = (string) $data['price_mode'];
            $row = [
                'price_mode' => $mode,
                'shipping_tax_class_key' => $shippingClass,
                'revision' => $revision,
                'updated_at' => $now,
            ];
            if ($existing === null) {
                DB::table('pricing_settings')->insert($row + ['id' => 1, 'created_at' => $now]);
            } else {
                DB::table('pricing_settings')->where('id', 1)->update($row);
            }
            foreach ($rules as $rule) {
                DB::table('tax_rules')->insert([
                    'id' => $rule['id'],
                    'class_key' => $rule['class_key'],
                    'name' => $rule['name'],
                    'country_code' => $rule['match']['country_code'],
                    'region_code' => $rule['match']['region_code'],
                    'postal_pattern' => $rule['match']['postal_pattern'],
                    'rate_numerator' => $rule['rate_numerator'],
                    'rate_denominator' => $rule['rate_denominator'],
                    'status' => $rule['status'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            app(RecordEvent::class)->record('platform.audit.recorded', 'pricing:tax:'.$revision, [
                'action' => 'pricing.tax.saved', 'subject_type' => 'pricing_settings', 'subject_id' => '1',
                'actor_type' => 'staff', 'actor_id' => $actor->id, 'metadata' => ['version' => $revision],
            ]);

            return [
                'revision' => $revision,
                'price_mode' => $mode,
                'shipping_tax_class_key' => $shippingClass,
            ];
        });
    }

    /** @param list<array{key: string, name: string}> $classes */
    private function syncClasses(array $classes, mixed $now): void
    {
        $kept = [];
        foreach ($classes as $class) {
            $key = (string) $class['key'];
            $kept[] = $key;
            $existing = DB::table('tax_classes')->where('class_key', $key)->first();
            $row = ['name' => (string) $class['name'], 'updated_at' => $now];
            if ($existing === null) {
                DB::table('tax_classes')->insert($row + [
                    'class_key' => $key, 'is_builtin' => $key === 'standard', 'created_at' => $now,
                ]);
            } else {
                DB::table('tax_classes')->where('class_key', $key)->update($row);
            }
        }
        $removals = DB::table('tax_classes')->whereNotIn('class_key', $kept)->get();
        foreach ($removals as $removal) {
            $key = $this->string($removal, 'class_key');
            abort_if((bool) $this->int($removal, 'is_builtin'), 422);
            if (DB::table('product_variants')->where('tax_class_key', $key)->exists()) {
                throw ValidationException::withMessages(['rules.classes' => 'A tax class used by a product cannot be removed.']);
            }
            DB::table('tax_classes')->where('class_key', $key)->delete();
        }
    }

    /** @return list<string> */
    private function salesCountries(): array
    {
        $values = DB::table('store_settings')->where('id', 1)->value('values');
        $settings = is_string($values) ? json_decode($values, true) : null;
        $allowed = array_column(app(SetupChoices::class)->countries(), 'code');
        $countries = [];
        if (is_array($settings) && is_array($settings['sales_countries'] ?? null)) {
            foreach ($settings['sales_countries'] as $country) {
                if (is_string($country) && in_array($country, $allowed, true)) {
                    $countries[] = $country;
                }
            }
        }

        return $countries;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
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
