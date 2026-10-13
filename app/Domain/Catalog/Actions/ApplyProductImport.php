<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ApplyProductImport
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     import_id: string,
     *     applied: bool,
     *     replayed: bool,
     *     rows: list<array{line: int, sku: string, intent: string, valid: bool, errors: list<string>, product_id: string|null, result: string}>,
     *     valid_count: int,
     *     error_count: int,
     *     create_count: int,
     *     update_count: int
     * }
     */
    public function apply(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('catalog.write')) {
            throw new AuthorizationException;
        }
        $importId = is_string($input['import_id'] ?? null) ? $input['import_id'] : '';
        abort_unless($importId !== '', 404);

        return DB::transaction(function () use ($actor, $importId): array {
            $record = DB::table('product_import_files')->where('id', $importId)->lockForUpdate()->first();
            abort_unless($record !== null, 404);
            if (is_string($record->apply_result) && $record->apply_result !== '') {
                $stored = json_decode($record->apply_result, true, flags: JSON_THROW_ON_ERROR);
                if (is_array($stored)) {
                    $stored['applied'] = true;
                    $stored['replayed'] = true;

                    return $this->normalizedResult($stored);
                }
            }
            $preview = app(PreviewProductImport::class)->preview($actor, ['import_id' => $importId]);
            $saved = [];
            $creates = 0;
            $updates = 0;
            $errors = 0;
            foreach ($preview['rows'] as $row) {
                $outcome = $this->applyRow($actor, $row);
                $saved[] = $outcome;
                if ($outcome['result'] === 'created') {
                    $creates++;
                } elseif ($outcome['result'] === 'updated') {
                    $updates++;
                } else {
                    $errors++;
                }
            }
            $result = [
                'import_id' => $importId,
                'applied' => true,
                'replayed' => false,
                'rows' => $saved,
                'valid_count' => $creates + $updates,
                'error_count' => $errors,
                'create_count' => $creates,
                'update_count' => $updates,
            ];
            DB::table('product_import_files')->where('id', $importId)->update([
                'applied_at' => now(),
                'apply_result' => json_encode($result, JSON_THROW_ON_ERROR),
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'catalog:import:apply:'.$importId, [
                'action' => 'catalog.import.applied',
                'subject_type' => 'product_import',
                'subject_id' => $importId,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => [
                    'create_count' => $creates,
                    'update_count' => $updates,
                    'error_count' => $errors,
                ],
            ]);

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array{
     *     import_id: string,
     *     applied: bool,
     *     replayed: bool,
     *     rows: list<array{line: int, sku: string, intent: string, valid: bool, errors: list<string>, product_id: string|null, result: string}>,
     *     valid_count: int,
     *     error_count: int,
     *     create_count: int,
     *     update_count: int
     * }
     */
    private function normalizedResult(array $stored): array
    {
        $rows = [];
        foreach (is_array($stored['rows'] ?? null) ? $stored['rows'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $errors = [];
            foreach (is_array($row['errors'] ?? null) ? $row['errors'] : [] as $error) {
                if (is_string($error)) {
                    $errors[] = $error;
                }
            }
            $rows[] = [
                'line' => (int) ($row['line'] ?? 0),
                'sku' => is_string($row['sku'] ?? null) ? $row['sku'] : '',
                'intent' => is_string($row['intent'] ?? null) ? $row['intent'] : '',
                'valid' => (bool) ($row['valid'] ?? false),
                'errors' => $errors,
                'product_id' => is_string($row['product_id'] ?? null) ? $row['product_id'] : null,
                'result' => is_string($row['result'] ?? null) ? $row['result'] : 'skipped',
            ];
        }

        return [
            'import_id' => is_string($stored['import_id'] ?? null) ? $stored['import_id'] : '',
            'applied' => true,
            'replayed' => true,
            'rows' => $rows,
            'valid_count' => (int) ($stored['valid_count'] ?? 0),
            'error_count' => (int) ($stored['error_count'] ?? 0),
            'create_count' => (int) ($stored['create_count'] ?? 0),
            'update_count' => (int) ($stored['update_count'] ?? 0),
        ];
    }

    /**
     * @param  array{line: int, sku: string, intent: string, valid: bool, errors: list<string>, product_id: string|null, values?: array<string, string>}  $row
     * @return array{line: int, sku: string, intent: string, valid: bool, errors: list<string>, product_id: string|null, result: string}
     */
    private function applyRow(StaffAccount $actor, array $row): array
    {
        $result = [
            'line' => $row['line'],
            'sku' => $row['sku'],
            'intent' => $row['intent'],
            'valid' => $row['valid'],
            'errors' => $row['errors'],
            'product_id' => $row['product_id'],
            'result' => 'skipped',
        ];
        if (! $row['valid']) {
            return $result;
        }
        $values = is_array($row['values'] ?? null) ? $row['values'] : [];
        try {
            $saved = app(SaveProduct::class)->save($actor, $this->saveInput($values, $row['intent'], $row['product_id']));
            $result['product_id'] = $saved['id'];
            $result['result'] = $row['intent'] === 'update' ? 'updated' : 'created';
        } catch (ValidationException $exception) {
            $messages = [];
            foreach ($exception->errors() as $fieldErrors) {
                foreach ($fieldErrors as $message) {
                    if (is_string($message)) {
                        $messages[] = $message;
                    }
                }
            }
            $result['valid'] = false;
            $result['errors'] = $messages === [] ? ['This row could not be saved.'] : $messages;
            $result['result'] = 'skipped';
        } catch (InvalidArgumentException $exception) {
            $result['valid'] = false;
            $result['errors'] = ['This row could not be saved.'];
            $result['result'] = 'skipped';
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            $result['valid'] = false;
            $result['errors'] = ['This product changed. Reload before applying.'];
            $result['result'] = 'skipped';
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    private function saveInput(array $values, string $intent, ?string $productId): array
    {
        $input = [
            'title' => $values['title'] ?? '',
            'slug' => $values['slug'] ?? '',
            'description' => $values['description'] ?? '',
            'status' => $values['status'] ?? 'draft',
            'sku' => $values['sku'] ?? '',
            'price_amount' => (int) ($values['price_amount'] ?? 0),
            'image_alt' => $values['image_alt'] ?? '',
        ];
        if (($values['type'] ?? '') !== '') {
            $input['type'] = $values['type'];
        }
        $imageId = $values['image_asset_id'] ?? '';
        if ($intent === 'update' && $productId !== null) {
            $input['id'] = $productId;
            $input['version'] = (int) DB::table('products')->where('id', $productId)->value('version');
            if ($imageId === '') {
                $imageId = (string) DB::table('product_media')->where('product_id', $productId)->where('sort_order', 0)->value('media_asset_id');
            }
            if (($values['image_alt'] ?? '') === '') {
                $input['image_alt'] = (string) DB::table('product_media')->where('product_id', $productId)->where('sort_order', 0)->value('alt');
            }
        }
        $input['image_asset_id'] = $imageId;
        if (array_key_exists('category_paths', $values)) {
            $input['category_ids'] = $this->categoryIds($values['category_paths']);
        }
        if (array_key_exists('schema_key', $values) || $this->attributeValues($values) !== []) {
            $schemaKey = $values['schema_key'] ?? '';
            $schemaId = $schemaKey === '' ? null : DB::table('product_schemas')->where('semantic_key', $schemaKey)->value('id');
            $input['schema_id'] = is_string($schemaId) ? $schemaId : null;
            $input['attributes'] = $this->attributeValues($values);
        }

        return $input;
    }

    /** @return list<string> */
    private function categoryIds(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $paths = preg_split('/[|;]/', $raw) ?: [];
        $ids = [];
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            $id = DB::table('categories')->where('path', $path)->value('id');
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function attributeValues(array $values): array
    {
        $attributes = [];
        foreach ($values as $field => $value) {
            if (str_starts_with($field, 'attr:')) {
                $attributes[substr($field, 5)] = $value;
            }
        }

        return $attributes;
    }
}
