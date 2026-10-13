<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Support\ProductAttributes;
use App\Domain\Catalog\Support\ProductSchemaPayload;
use App\Domain\Catalog\Support\ProductSchemaWriter;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $data = ProductSchemaPayload::fromInput($input);
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
                    $versionId = app(ProductSchemaWriter::class)->writeVersion($id, 1, $data, $now);
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
                        app(ProductSchemaWriter::class)->attachFields($currentVersionId, $data['attributes'], $now);
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
