<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Support\ProductAttributes;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveProductSchema
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, key: string, version: int, schema_version: int, comparison_group: string}
     */
    public function save(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('catalog.write')) {
            throw new AuthorizationException;
        }
        $data = Validator::make(['schema' => $input], [
            'schema' => ['required', 'array:id,version,key,label,comparison_group,default_template_key,attributes'],
            'schema.id' => ['nullable', 'uuid'],
            'schema.version' => ['required_with:schema.id', 'integer', 'min:1', 'max:4294967295'],
            'schema.key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/'],
            'schema.label' => ['required', 'string', 'max:80'],
            'schema.comparison_group' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/'],
            'schema.default_template_key' => ['required', 'string', Rule::in(['product.default'])],
            'schema.attributes' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'schema.attributes.*' => ['required', 'array'],
            'schema.attributes.*.key' => ['required', 'string', 'max:80', 'distinct:strict', 'regex:/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*){0,4}$/'],
            'schema.attributes.*.type' => ['required', Rule::in(['string', 'integer', 'decimal', 'boolean', 'date', 'enum'])],
            'schema.attributes.*.unit' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z%][A-Za-z0-9%]{0,15}$/'],
            'schema.attributes.*.required' => ['required', 'boolean'],
            'schema.attributes.*.comparable' => ['required', 'boolean'],
            'schema.attributes.*.filterable' => ['required', 'boolean'],
            'schema.attributes.*.label' => ['required', 'string', 'max:120'],
            'schema.attributes.*.options' => ['exclude_unless:schema.attributes.*.type,enum', 'required', 'array', 'list', 'min:2', 'max:30'],
            'schema.attributes.*.options.*' => ['required', 'string', 'max:40', 'distinct:strict', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ])->validate()['schema'];
        foreach ($data['attributes'] as $index => $attribute) {
            $unit = $attribute['unit'] ?? null;
            if ($unit === '') {
                $data['attributes'][$index]['unit'] = null;
                $unit = null;
            }
            if ($unit !== null && ! in_array($attribute['type'], ['integer', 'decimal'], true)) {
                throw ValidationException::withMessages(['schema.attributes.'.$index.'.unit' => 'Units apply only to numeric attributes.']);
            }
            if ($attribute['type'] !== 'enum') {
                $data['attributes'][$index]['options'] = [];
            }
        }
        try {
            return DB::transaction(function () use ($actor, $data): array {
                $now = now();
                $existing = null;
                if (isset($data['id'])) {
                    $existing = DB::table('product_schemas')->where('id', $data['id'])->lockForUpdate()->first();
                    abort_unless($existing !== null, 404);
                    abort_unless((int) $existing->version === (int) $data['version'], 409, 'This schema changed. Reload before saving.');
                    abort_if((int) $existing->version >= 4294967295, 409);
                }
                $id = isset($data['id']) ? (string) $data['id'] : (string) Str::uuid();
                $editorVersion = $existing === null ? 1 : ((int) $existing->version) + 1;
                $assigned = $existing !== null && DB::table('products')->where('schema_version_id', $existing->current_version_id)->lockForUpdate()->exists();
                if ($assigned && $this->fieldsChanged($existing->current_version_id !== null ? (string) $existing->current_version_id : '', $data['attributes'])) {
                    throw ValidationException::withMessages(['schema.attributes' => 'Assigned schemas keep their fields until a later migration preview.']);
                }
                $schemaRow = [
                    'semantic_key' => $data['key'],
                    'label' => $data['label'],
                    'comparison_group' => $data['comparison_group'],
                    'default_template_key' => $data['default_template_key'],
                    'version' => $editorVersion,
                    'updated_at' => $now,
                ];
                if ($existing === null) {
                    DB::table('product_schemas')->insert($schemaRow + [
                        'id' => $id, 'current_version_id' => null, 'created_at' => $now,
                    ]);
                    $versionId = $this->writeVersion($id, 1, $data, $now);
                    DB::table('product_schemas')->where('id', $id)->update(['current_version_id' => $versionId]);
                    $schemaVersion = 1;
                } else {
                    $currentVersionId = is_string($existing->current_version_id) ? $existing->current_version_id : '';
                    $schemaVersion = (int) DB::table('product_schema_versions')->where('id', $currentVersionId)->value('version');
                    if (! $assigned) {
                        DB::table('product_schema_version_fields')->where('schema_version_id', $currentVersionId)->delete();
                        DB::table('product_schema_versions')->where('id', $currentVersionId)->update([
                            'comparison_group' => $data['comparison_group'],
                            'default_template_key' => $data['default_template_key'],
                            'updated_at' => $now,
                        ]);
                        $this->attachFields($currentVersionId, $data['attributes'], $now);
                    } else {
                        DB::table('product_schema_versions')->where('id', $currentVersionId)->update([
                            'comparison_group' => $data['comparison_group'],
                            'default_template_key' => $data['default_template_key'],
                            'updated_at' => $now,
                        ]);
                    }
                    DB::table('product_schemas')->where('id', $id)->update($schemaRow);
                }
                app(RecordEvent::class)->record('platform.audit.recorded', 'catalog:schema:'.$id.':'.$editorVersion, [
                    'action' => 'catalog.schema.saved', 'subject_type' => 'product_schema', 'subject_id' => $id,
                    'actor_type' => 'staff', 'actor_id' => $actor->id, 'metadata' => ['version' => $editorVersion],
                ]);

                return [
                    'id' => $id,
                    'key' => $data['key'],
                    'version' => $editorVersion,
                    'schema_version' => $schemaVersion,
                    'comparison_group' => $data['comparison_group'],
                ];
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (DB::table('product_schemas')->where('semantic_key', $data['key'])->when(isset($data['id']), fn ($query) => $query->where('id', '!=', $data['id']))->exists()) {
                throw ValidationException::withMessages(['schema.key' => 'This schema key is already used.']);
            }
            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeVersion(string $schemaId, int $version, array $data, mixed $now): string
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
    private function attachFields(string $versionId, array $attributes, mixed $now): void
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
    private function definitionId(array $attribute, int $index, mixed $now): string
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

    /**
     * @param  list<array<string, mixed>>  $attributes
     */
    private function fieldsChanged(string $versionId, array $attributes): bool
    {
        if ($versionId === '') {
            return true;
        }
        $current = app(ProductAttributes::class)->fieldsForVersion($versionId);
        if (count($current) !== count($attributes)) {
            return true;
        }
        foreach ($attributes as $index => $attribute) {
            $field = $current[$index];
            if ($field['key'] !== $attribute['key']
                || $field['type'] !== $attribute['type']
                || $field['unit'] !== ($attribute['unit'] ?? null)
                || $field['required'] !== $attribute['required']
                || $field['comparable'] !== $attribute['comparable']
                || $field['filterable'] !== $attribute['filterable']
                || $field['label'] !== $attribute['label']
                || $field['options'] !== ($attribute['options'] ?? [])) {
                return true;
            }
        }

        return false;
    }
}
