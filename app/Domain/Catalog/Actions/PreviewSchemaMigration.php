<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Support\ProductAttributes;
use App\Domain\Catalog\Support\ProductSchemaPayload;
use App\Domain\Catalog\Support\ProductSchemaWriter;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class PreviewSchemaMigration
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{affected_products: int, invalid_values: list<array{product_id: string, title: string, field: string, message: string}>, comparison_changes: list<array{key: string, change: string, comparable: bool, filterable: bool}>, incompatible_fields: list<array{key: string, message: string}>, compatible: bool, can_apply: bool}
     */
    public function preview(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('catalog.write')) {
            throw new AuthorizationException;
        }
        $data = ProductSchemaPayload::fromInput($input);
        abort_unless(isset($data['id']) && is_string($data['id']), 404);
        $schema = DB::table('product_schemas')->where('id', $data['id'])->lockForUpdate()->first();
        abort_unless($schema !== null, 404);
        abort_unless((int) $schema->version === (int) $data['version'], 409, 'This schema changed. Reload before saving.');
        $writer = app(ProductSchemaWriter::class);
        $incompatible = [];
        $fields = $this->proposedFields($data['attributes']);
        foreach ($fields as $attribute) {
            $conflict = $writer->incompatibility($attribute);
            if ($conflict !== null) {
                $incompatible[] = $conflict;
            }
        }
        $versionIds = DB::table('product_schema_versions')->where('product_schema_id', $data['id'])->pluck('id');
        $currentFields = [];
        if (is_string($schema->current_version_id)) {
            $currentFields = app(ProductAttributes::class)->fieldsForVersion($schema->current_version_id);
        }
        $products = DB::table('products')->whereIn('schema_version_id', $versionIds)->orderBy('id')->get(['id', 'title']);
        $invalid = [];
        foreach ($products as $product) {
            if (count($invalid) >= 100) {
                break;
            }
            $values = app(ProductAttributes::class)->staffValues((string) $product->id);
            foreach (app(ProductAttributes::class)->problemsFor($fields, $values) as $problem) {
                $invalid[] = [
                    'product_id' => (string) $product->id,
                    'title' => (string) $product->title,
                    'field' => $problem['key'],
                    'message' => $problem['message'],
                ];
            }
        }

        return [
            'affected_products' => $products->count(),
            'invalid_values' => $invalid,
            'comparison_changes' => $this->comparisonChanges($currentFields, $fields),
            'incompatible_fields' => $incompatible,
            'compatible' => $incompatible === [],
            'can_apply' => $incompatible === [] && $invalid === [],
        ];
    }

    /**
     * @return list<array{key: string, type: string, unit: string|null, required: bool, comparable: bool, filterable: bool, label: string, options: list<string>}>
     */
    private function proposedFields(mixed $attributes): array
    {
        $fields = [];
        if (! is_array($attributes)) {
            return $fields;
        }
        foreach ($attributes as $attribute) {
            if (! is_array($attribute) || ! is_string($attribute['key'] ?? null) || ! is_string($attribute['type'] ?? null) || ! is_string($attribute['label'] ?? null)) {
                continue;
            }
            $options = [];
            if (isset($attribute['options']) && is_array($attribute['options'])) {
                foreach ($attribute['options'] as $option) {
                    if (is_string($option)) {
                        $options[] = $option;
                    }
                }
            }
            $fields[] = [
                'key' => $attribute['key'],
                'type' => $attribute['type'],
                'unit' => is_string($attribute['unit'] ?? null) ? $attribute['unit'] : null,
                'required' => (bool) ($attribute['required'] ?? false),
                'comparable' => (bool) ($attribute['comparable'] ?? false),
                'filterable' => (bool) ($attribute['filterable'] ?? false),
                'label' => $attribute['label'],
                'options' => $options,
            ];
        }

        return $fields;
    }

    /**
     * @param  list<array{key: string, comparable: bool, filterable: bool}>  $current
     * @param  list<array{key: string, comparable: bool, filterable: bool}>  $proposed
     * @return list<array{key: string, change: string, comparable: bool, filterable: bool}>
     */
    private function comparisonChanges(array $current, array $proposed): array
    {
        $currentByKey = [];
        foreach ($current as $field) {
            $currentByKey[$field['key']] = $field;
        }
        $proposedByKey = [];
        foreach ($proposed as $field) {
            $proposedByKey[$field['key']] = $field;
        }
        $changes = [];
        foreach ($proposedByKey as $key => $field) {
            if (! isset($currentByKey[$key])) {
                $changes[] = [
                    'key' => $key,
                    'change' => 'added',
                    'comparable' => (bool) $field['comparable'],
                    'filterable' => (bool) $field['filterable'],
                ];
            }
        }
        foreach ($currentByKey as $key => $field) {
            if (! isset($proposedByKey[$key])) {
                $changes[] = [
                    'key' => $key,
                    'change' => 'removed',
                    'comparable' => (bool) $field['comparable'],
                    'filterable' => (bool) $field['filterable'],
                ];
            }
        }

        return $changes;
    }
}
