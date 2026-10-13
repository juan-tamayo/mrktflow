<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProductAttributes
{
    /**
     * @return list<array{id: string, key: string, type: string, unit: string|null, required: bool, comparable: bool, filterable: bool, label: string, options: list<string>}>
     */
    public function fieldsForVersion(string $schemaVersionId): array
    {
        $fields = [];
        $rows = DB::table('product_schema_version_fields')
            ->join('attribute_definitions', 'product_schema_version_fields.attribute_definition_id', '=', 'attribute_definitions.id')
            ->where('product_schema_version_fields.schema_version_id', $schemaVersionId)
            ->orderBy('product_schema_version_fields.sort_order')
            ->select([
                'attribute_definitions.id', 'attribute_definitions.semantic_key', 'attribute_definitions.type',
                'attribute_definitions.unit', 'attribute_definitions.comparable', 'attribute_definitions.filterable',
                'product_schema_version_fields.label', 'product_schema_version_fields.required',
            ])
            ->get();
        foreach ($rows as $row) {
            $options = [];
            if ((string) $row->type === 'enum') {
                foreach (DB::table('attribute_enum_options')->where('attribute_definition_id', $row->id)->orderBy('sort_order')->pluck('option_value') as $option) {
                    if (is_string($option)) {
                        $options[] = $option;
                    }
                }
            }
            $fields[] = [
                'id' => (string) $row->id,
                'key' => (string) $row->semantic_key,
                'type' => (string) $row->type,
                'unit' => is_string($row->unit) ? $row->unit : null,
                'required' => (bool) $row->required,
                'comparable' => (bool) $row->comparable,
                'filterable' => (bool) $row->filterable,
                'label' => (string) $row->label,
                'options' => $options,
            ];
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function replaceValues(string $productId, string $schemaVersionId, array $values, mixed $now): void
    {
        $fields = $this->fieldsForVersion($schemaVersionId);
        $allowed = [];
        foreach ($fields as $field) {
            $allowed[$field['key']] = $field;
        }
        foreach (array_keys($values) as $key) {
            if (! isset($allowed[$key])) {
                throw ValidationException::withMessages(['product.attributes' => 'Use only attributes defined by the selected schema.']);
            }
        }
        $rows = [];
        foreach ($fields as $field) {
            $present = array_key_exists($field['key'], $values);
            $raw = $present ? $values[$field['key']] : null;
            if (! $present || $this->isBlank($raw)) {
                if ($field['required']) {
                    throw ValidationException::withMessages(['product.attributes.'.$field['key'] => 'This attribute is required.']);
                }

                continue;
            }
            $rows[] = $this->typedRow($productId, $field, $raw, $now);
        }
        DB::table('attribute_values')->where('product_id', $productId)->delete();
        foreach ($rows as $row) {
            DB::table('attribute_values')->insert($row);
        }
    }

    public function clearValues(string $productId): void
    {
        DB::table('attribute_values')->where('product_id', $productId)->delete();
    }

    /**
     * @param  list<array{id?: string, key: string, type: string, unit: string|null, required: bool, comparable: bool, filterable: bool, label: string, options: list<string>}>  $fields
     * @param  array<string, mixed>  $values
     * @return list<array{key: string, message: string}>
     */
    public function problemsFor(array $fields, array $values): array
    {
        $problems = [];
        foreach ($fields as $field) {
            $present = array_key_exists($field['key'], $values);
            $raw = $present ? $values[$field['key']] : null;
            if (! $present || $this->isBlank($raw)) {
                if ($field['required']) {
                    $problems[] = ['key' => $field['key'], 'message' => 'This attribute is required.'];
                }

                continue;
            }
            $typed = $field;
            $typed['id'] = $field['id'] ?? '00000000-0000-4000-8000-000000000000';
            try {
                $this->typedRow('00000000-0000-4000-8000-000000000000', $typed, $raw, now());
            } catch (ValidationException $exception) {
                $messages = $exception->validator->errors()->all();
                $problems[] = ['key' => $field['key'], 'message' => $messages[0] ?? 'This attribute is invalid.'];
            }
        }

        return $problems;
    }

    /** @return array<string, string|bool> */
    public function staffValues(string $productId): array
    {
        $values = [];
        foreach ($this->valueRows($productId) as $row) {
            $values[$row['key']] = $row['staff'];
        }

        return $values;
    }

    /** @return list<array{label: string, value: string}> */
    public function publicAttributes(string $productId): array
    {
        $attributes = [];
        foreach ($this->valueRows($productId) as $row) {
            $attributes[] = ['label' => $row['label'], 'value' => $row['display']];
        }

        return $attributes;
    }

    /**
     * @return list<array{key: string, label: string, staff: string|bool, display: string}>
     */
    private function valueRows(string $productId): array
    {
        $rows = [];
        $records = DB::table('attribute_values')
            ->join('attribute_definitions', 'attribute_values.attribute_definition_id', '=', 'attribute_definitions.id')
            ->join('products', 'attribute_values.product_id', '=', 'products.id')
            ->join('product_schema_version_fields', function ($join): void {
                $join->on('product_schema_version_fields.attribute_definition_id', '=', 'attribute_definitions.id')
                    ->on('product_schema_version_fields.schema_version_id', '=', 'products.schema_version_id');
            })
            ->where('attribute_values.product_id', $productId)
            ->orderBy('product_schema_version_fields.sort_order')
            ->select([
                'attribute_definitions.semantic_key', 'attribute_definitions.type', 'attribute_definitions.unit',
                'product_schema_version_fields.label', 'attribute_values.string_value', 'attribute_values.integer_value',
                'attribute_values.decimal_value', 'attribute_values.boolean_value', 'attribute_values.date_value',
                'attribute_values.enum_value',
            ])
            ->get();
        foreach ($records as $record) {
            $type = (string) $record->type;
            $staff = '';
            $display = '';
            if ($type === 'string' && is_string($record->string_value)) {
                $staff = $record->string_value;
                $display = $record->string_value;
            } elseif ($type === 'integer' && $record->integer_value !== null) {
                $staff = (string) (int) $record->integer_value;
                $display = $staff;
            } elseif ($type === 'decimal' && $record->decimal_value !== null) {
                $staff = $this->trimDecimal((string) $record->decimal_value);
                $display = $staff;
            } elseif ($type === 'boolean' && $record->boolean_value !== null) {
                $flag = (bool) $record->boolean_value;
                $staff = $flag;
                $display = $flag ? 'Yes' : 'No';
            } elseif ($type === 'date' && is_string($record->date_value)) {
                $staff = $record->date_value;
                $display = $record->date_value;
            } elseif ($type === 'enum' && is_string($record->enum_value)) {
                $staff = $record->enum_value;
                $display = $record->enum_value;
            } else {
                continue;
            }
            $rows[] = [
                'key' => (string) $record->semantic_key,
                'label' => (string) $record->label,
                'staff' => $staff,
                'display' => $display,
            ];
        }

        return $rows;
    }

    /**
     * @param  array{id: string, key: string, type: string, unit: string|null, required: bool, comparable: bool, filterable: bool, label: string, options: list<string>}  $field
     * @return array<string, mixed>
     */
    private function typedRow(string $productId, array $field, mixed $raw, mixed $now): array
    {
        $row = [
            'id' => (string) Str::uuid(),
            'product_id' => $productId,
            'attribute_definition_id' => $field['id'],
            'string_value' => null,
            'integer_value' => null,
            'decimal_value' => null,
            'boolean_value' => null,
            'date_value' => null,
            'enum_value' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $path = 'product.attributes.'.$field['key'];
        if ($field['type'] === 'string') {
            if (! is_string($raw) || $raw === '' || mb_strlen($raw, 'UTF-8') > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $raw) === 1) {
                throw ValidationException::withMessages([$path => 'Enter plain text for this attribute.']);
            }
            $row['string_value'] = $raw;
        } elseif ($field['type'] === 'integer') {
            if (is_int($raw)) {
                $raw = (string) $raw;
            }
            if (! is_string($raw) || preg_match('/^(?:0|[1-9]\d{0,11})$/D', $raw) !== 1) {
                throw ValidationException::withMessages([$path => 'Enter a whole number for this attribute.']);
            }
            $row['integer_value'] = (int) $raw;
        } elseif ($field['type'] === 'decimal') {
            if (is_int($raw) || is_float($raw)) {
                throw ValidationException::withMessages([$path => 'Enter a decimal number as text.']);
            }
            if (! is_string($raw) || preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,6})?$/D', $raw) !== 1) {
                throw ValidationException::withMessages([$path => 'Enter a decimal number for this attribute.']);
            }
            $row['decimal_value'] = $raw;
        } elseif ($field['type'] === 'boolean') {
            if (! in_array($raw, [true, false, 1, 0, '1', '0', 'true', 'false'], true)) {
                throw ValidationException::withMessages([$path => 'Choose yes or no for this attribute.']);
            }
            $row['boolean_value'] = in_array($raw, [true, 1, '1', 'true'], true);
        } elseif ($field['type'] === 'date') {
            if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw) !== 1) {
                throw ValidationException::withMessages([$path => 'Enter a date as YYYY-MM-DD.']);
            }
            $row['date_value'] = $raw;
        } elseif ($field['type'] === 'enum') {
            if (! is_string($raw) || ! in_array($raw, $field['options'], true)) {
                throw ValidationException::withMessages([$path => 'Choose one of the allowed values.']);
            }
            $row['enum_value'] = $raw;
        } else {
            throw ValidationException::withMessages([$path => 'This attribute type is not supported.']);
        }

        return $row;
    }

    private function isBlank(mixed $raw): bool
    {
        return $raw === null || $raw === '';
    }

    private function trimDecimal(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '' ? '0' : $value;
    }
}
