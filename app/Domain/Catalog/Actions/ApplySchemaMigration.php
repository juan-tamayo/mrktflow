<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Support\ProductSchemaPayload;
use App\Domain\Catalog\Support\ProductSchemaWriter;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApplySchemaMigration
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, key: string, version: int, schema_version: int, comparison_group: string}
     */
    public function apply(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('catalog.write')) {
            throw new AuthorizationException;
        }
        $data = ProductSchemaPayload::fromInput($input);
        abort_unless(isset($data['id']) && is_string($data['id']), 404);

        return DB::transaction(function () use ($actor, $data): array {
            $preview = app(PreviewSchemaMigration::class)->preview($actor, $data);
            if (! $preview['can_apply']) {
                if (! $preview['compatible']) {
                    throw ValidationException::withMessages([
                        'schema.attributes' => $preview['incompatible_fields'][0]['message'] ?? 'This schema change is incompatible.',
                    ]);
                }
                throw ValidationException::withMessages([
                    'schema.attributes' => 'Existing product values are invalid for the proposed schema.',
                ]);
            }
            $schema = DB::table('product_schemas')->where('id', $data['id'])->lockForUpdate()->first();
            abort_unless($schema !== null, 404);
            abort_if((int) $schema->version >= 4294967295, 409);
            $now = now();
            $currentSchemaVersion = (int) DB::table('product_schema_versions')->where('id', $schema->current_version_id)->value('version');
            $nextSchemaVersion = $currentSchemaVersion + 1;
            $versionId = app(ProductSchemaWriter::class)->writeVersion($data['id'], $nextSchemaVersion, $data, $now);
            $priorVersionIds = DB::table('product_schema_versions')->where('product_schema_id', $data['id'])->where('id', '!=', $versionId)->pluck('id');
            $productIds = DB::table('products')->whereIn('schema_version_id', $priorVersionIds)->pluck('id');
            if ($productIds->isNotEmpty()) {
                DB::table('products')->whereIn('id', $productIds)->update(['schema_version_id' => $versionId, 'updated_at' => $now]);
                $kept = DB::table('product_schema_version_fields')->where('schema_version_id', $versionId)->pluck('attribute_definition_id');
                DB::table('attribute_values')->whereIn('product_id', $productIds)->whereNotIn('attribute_definition_id', $kept)->delete();
            }
            $editorVersion = ((int) $schema->version) + 1;
            DB::table('product_schemas')->where('id', $data['id'])->update([
                'semantic_key' => $data['key'],
                'label' => $data['label'],
                'comparison_group' => $data['comparison_group'],
                'default_template_key' => $data['default_template_key'],
                'current_version_id' => $versionId,
                'version' => $editorVersion,
                'updated_at' => $now,
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'catalog:schema:'.$data['id'].':'.$editorVersion, [
                'action' => 'catalog.schema.migrated', 'subject_type' => 'product_schema', 'subject_id' => $data['id'],
                'actor_type' => 'staff', 'actor_id' => $actor->id, 'metadata' => ['schema_version' => $nextSchemaVersion],
            ]);

            return [
                'id' => $data['id'],
                'key' => $data['key'],
                'version' => $editorVersion,
                'schema_version' => $nextSchemaVersion,
                'comparison_group' => $data['comparison_group'],
            ];
        });
    }
}
