<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProductSchemaWriter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function writeVersion(string $schemaId, int $version, array $data, mixed $now): string
    {
        $versionId = (string) Str::uuid();
        DB::table('product_schema_versions')->insert([
            'id' => $versionId,
            'product_schema_id' => $schemaId,
            'version' => $version,
            'comparison_group' => $data['comparison_group'],
            'default_template_key' => $data['default_template_key'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->attachFields($versionId, $data['attributes'], $now);

        return $versionId;
    }

    /**
     * @param  list<array<string, mixed>>  $attributes
     */
    public function attachFields(string $versionId, array $attributes, mixed $now): void
    {
        foreach ($attributes as $index => $attribute) {
            $definitionId = $this->definitionId($attribute, $index, $now);
            DB::table('product_schema_version_fields')->insert([
                'id' => (string) Str::uuid(),
                'schema_version_id' => $versionId,
                'attribute_definition_id' => $definitionId,
                'label' => $attribute['label'],
                'required' => $attribute['required'],
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attribute
     */
    public function definitionId(array $attribute, int $index, mixed $now): string
    {
        $existing = DB::table('attribute_definitions')->where('semantic_key', $attribute['key'])->lockForUpdate()->first();
        $unit = is_string($attribute['unit'] ?? null) ? $attribute['unit'] : null;
        if ($existing === null) {
            $id = (string) Str::uuid();
            DB::table('attribute_definitions')->insert([
                'id' => $id,
                'semantic_key' => $attribute['key'],
                'type' => $attribute['type'],
                'unit' => $unit,
                'comparable' => $attribute['comparable'],
                'filterable' => $attribute['filterable'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->replaceEnumOptions($id, $attribute['type'] === 'enum' ? $attribute['options'] : [], $now);

            return $id;
        }
        $options = [];
        if ((string) $existing->type === 'enum') {
            foreach (DB::table('attribute_enum_options')->where('attribute_definition_id', $existing->id)->orderBy('sort_order')->pluck('option_value') as $option) {
                if (is_string($option)) {
                    $options[] = $option;
                }
            }
        }
        $same = (string) $existing->type === $attribute['type']
            && ($existing->unit === $unit || ($existing->unit === null && $unit === null))
            && (bool) $existing->comparable === (bool) $attribute['comparable']
            && (bool) $existing->filterable === (bool) $attribute['filterable']
            && $options === ($attribute['type'] === 'enum' ? $attribute['options'] : []);
        if (! $same) {
            throw ValidationException::withMessages([
                'schema.attributes.'.$index.'.key' => 'This attribute key already has a different type or comparison meaning.',
            ]);
        }

        return (string) $existing->id;
    }

    /**
     * @param  array<string, mixed>  $attribute
     * @return array{key: string, message: string}|null
     */
    public function incompatibility(array $attribute): ?array
    {
        $existing = DB::table('attribute_definitions')->where('semantic_key', $attribute['key'])->first();
        if ($existing === null) {
            return null;
        }
        $unit = is_string($attribute['unit'] ?? null) ? $attribute['unit'] : null;
        $options = [];
        if ((string) $existing->type === 'enum') {
            foreach (DB::table('attribute_enum_options')->where('attribute_definition_id', $existing->id)->orderBy('sort_order')->pluck('option_value') as $option) {
                if (is_string($option)) {
                    $options[] = $option;
                }
            }
        }
        $same = (string) $existing->type === $attribute['type']
            && ($existing->unit === $unit || ($existing->unit === null && $unit === null))
            && (bool) $existing->comparable === (bool) $attribute['comparable']
            && (bool) $existing->filterable === (bool) $attribute['filterable']
            && $options === ($attribute['type'] === 'enum' ? ($attribute['options'] ?? []) : []);
        if ($same) {
            return null;
        }

        return [
            'key' => (string) $attribute['key'],
            'message' => 'This attribute key already has a different type or comparison meaning.',
        ];
    }

    /** @param list<string> $options */
    private function replaceEnumOptions(string $definitionId, array $options, mixed $now): void
    {
        DB::table('attribute_enum_options')->where('attribute_definition_id', $definitionId)->delete();
        foreach ($options as $index => $option) {
            DB::table('attribute_enum_options')->insert([
                'id' => (string) Str::uuid(),
                'attribute_definition_id' => $definitionId,
                'option_value' => $option,
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
