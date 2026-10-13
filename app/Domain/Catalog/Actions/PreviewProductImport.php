<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Support\ProductAttributes;
use App\Domain\Catalog\Support\ProductImportCsv;
use App\Domain\Catalog\Support\ReservedCatalogSlugs;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PreviewProductImport
{
    public const MAX_BYTES = 1_048_576;

    public const MAX_ROWS = 500;

    public const MAX_COLUMNS = 40;

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     import_id: string,
     *     sample_columns: list<string>,
     *     headers: list<string>,
     *     mapping: array<string, string>,
     *     unmapped: list<string>,
     *     rows: list<array{line: int, sku: string, intent: string, valid: bool, errors: list<string>, product_id: string|null, values?: array<string, string>}>,
     *     valid_count: int,
     *     error_count: int,
     *     create_count: int,
     *     update_count: int
     * }
     */
    public function preview(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('catalog.write')) {
            throw new AuthorizationException;
        }
        $file = $input['file'] ?? null;
        $importId = is_string($input['import_id'] ?? null) ? $input['import_id'] : null;
        $mappingInput = is_array($input['mapping'] ?? null) ? $input['mapping'] : [];
        if ($file instanceof UploadedFile) {
            $stored = $this->storeUpload($actor, $file);
            $importId = $stored['id'];
            $contents = $stored['contents'];
            $headers = $stored['headers'];
        } else {
            if ($importId === null || ! Str::isUuid($importId)) {
                throw ValidationException::withMessages(['file' => 'Upload a CSV catalog file.']);
            }
            $record = DB::table('product_import_files')->where('id', $importId)->first();
            abort_unless($record !== null, 404);
            $key = is_string($record->storage_key) ? $record->storage_key : '';
            $contents = Storage::disk('local')->get($key);
            abort_unless(is_string($contents), 404);
            $parsed = ProductImportCsv::parse($contents);
            $headers = $parsed['headers'];
        }
        $mapping = $this->resolvedMapping($headers, $mappingInput);
        if (! in_array('sku', $mapping, true)) {
            throw ValidationException::withMessages(['mapping' => 'Map a CSV column to SKU.']);
        }
        DB::table('product_import_files')->where('id', $importId)->update([
            'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR),
        ]);
        $parsed = ProductImportCsv::parse($contents);
        if (count($parsed['headers']) > self::MAX_COLUMNS) {
            throw ValidationException::withMessages(['file' => 'Use at most 40 CSV columns.']);
        }
        if (count($parsed['rows']) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'Use at most 500 data rows in a dry-run.']);
        }
        $rows = $this->validateRows($parsed['headers'], $parsed['rows'], $mapping);
        $valid = 0;
        $errors = 0;
        $creates = 0;
        $updates = 0;
        foreach ($rows as $row) {
            if ($row['valid']) {
                $valid++;
                if ($row['intent'] === 'create') {
                    $creates++;
                } else {
                    $updates++;
                }
            } else {
                $errors++;
            }
        }
        $unmapped = [];
        foreach ($headers as $header) {
            if (! isset($mapping[$header]) || $mapping[$header] === 'ignore') {
                $unmapped[] = $header;
            }
        }

        return [
            'import_id' => (string) $importId,
            'sample_columns' => ProductImportCsv::sampleColumns(),
            'headers' => $headers,
            'mapping' => $mapping,
            'unmapped' => $unmapped,
            'rows' => $rows,
            'valid_count' => $valid,
            'error_count' => $errors,
            'create_count' => $creates,
            'update_count' => $updates,
        ];
    }

    /**
     * @param  array{
     *     rows: list<array{line: int, sku: string, intent: string, valid: bool, errors: list<string>, product_id: string|null}>
     * }  $preview
     */
    public function errorCsv(array $preview): string
    {
        $lines = [ProductImportCsv::line(['line', 'sku', 'intent', 'errors'])];
        foreach ($preview['rows'] as $row) {
            if ($row['valid']) {
                continue;
            }
            $lines[] = ProductImportCsv::line([
                ProductImportCsv::neutralize((string) $row['line']),
                ProductImportCsv::neutralize($row['sku']),
                ProductImportCsv::neutralize($row['intent']),
                ProductImportCsv::neutralize(implode('; ', $row['errors'])),
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array{id: string, contents: string, headers: list<string>}
     */
    private function storeUpload(StaffAccount $actor, UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'Upload a valid CSV file.']);
        }
        $name = $file->getClientOriginalName();
        if (preg_match('/\.csv$/i', $name) !== 1) {
            throw ValidationException::withMessages(['file' => 'Upload a .csv file.']);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Keep catalog CSV files under 1 MB.']);
        }
        $contents = $file->get();
        if (! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages(['file' => 'Upload a CSV with a header row.']);
        }
        if (strlen($contents) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Keep catalog CSV files under 1 MB.']);
        }
        if (str_contains($contents, "\0") || ! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'Upload UTF-8 CSV without binary data.']);
        }
        if (preg_match('/<\?php|<script|<html/i', $contents) === 1) {
            throw ValidationException::withMessages(['file' => 'Upload a data-only CSV file.']);
        }
        $parsed = ProductImportCsv::parse($contents);
        $id = (string) Str::uuid();
        $key = 'catalog-imports/'.$id.'.csv';
        Storage::disk('local')->put($key, $contents);
        DB::table('product_import_files')->insert([
            'id' => $id,
            'staff_id' => $actor->id,
            'original_name' => Str::limit(basename($name), 200, ''),
            'storage_key' => $key,
            'checksum' => hash('sha256', $contents),
            'bytes' => strlen($contents),
            'headers' => json_encode($parsed['headers'], JSON_THROW_ON_ERROR),
            'mapping' => null,
            'created_at' => now(),
        ]);

        return ['id' => $id, 'contents' => $contents, 'headers' => $parsed['headers']];
    }

    /**
     * @param  list<string>  $headers
     * @param  array<mixed, mixed>  $mappingInput
     * @return array<string, string>
     */
    private function resolvedMapping(array $headers, array $mappingInput): array
    {
        $suggested = ProductImportCsv::suggestMapping($headers);
        $mapping = [];
        foreach ($headers as $header) {
            $provided = $mappingInput[$header] ?? null;
            if (is_string($provided) && $provided !== '') {
                if (! ProductImportCsv::isAllowedTarget($provided)) {
                    throw ValidationException::withMessages(['mapping' => 'Map columns only to catalog fields.']);
                }
                $mapping[$header] = $provided;

                continue;
            }
            if (isset($suggested[$header])) {
                $mapping[$header] = $suggested[$header];
            }
        }

        return $mapping;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array{line: int, cells: list<string>}>  $rows
     * @param  array<string, string>  $mapping
     * @return list<array{line: int, sku: string, intent: string, valid: bool, errors: list<string>, product_id: string|null, values: array<string, string>}>
     */
    private function validateRows(array $headers, array $rows, array $mapping): array
    {
        $index = [];
        foreach ($headers as $offset => $header) {
            if (isset($mapping[$header]) && $mapping[$header] !== 'ignore') {
                $index[$mapping[$header]] = $offset;
            }
        }
        $seenSkus = [];
        $report = [];
        foreach ($rows as $row) {
            $values = $this->mappedValues($index, $row['cells']);
            $sku = $values['sku'] ?? '';
            $errors = [];
            foreach ($values as $field => $value) {
                if (ProductImportCsv::isFormulaRisk($value)) {
                    $errors[] = $field.' contains a spreadsheet formula.';
                }
            }
            if ($sku === '' || ProductImportCsv::isFormulaRisk($sku) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $sku) !== 1) {
                if ($sku === '') {
                    $errors[] = 'SKU is required.';
                } elseif (! ProductImportCsv::isFormulaRisk($sku)) {
                    $errors[] = 'SKU is invalid.';
                }
            }
            $skuKey = mb_strtolower($sku, 'UTF-8');
            $duplicate = $sku !== '' && isset($seenSkus[$skuKey]);
            if ($duplicate) {
                $errors[] = 'This SKU is duplicated in the file.';
            }
            if ($sku !== '') {
                $seenSkus[$skuKey] = true;
            }
            $existing = null;
            if ($sku !== '' && ! $duplicate && ! ProductImportCsv::isFormulaRisk($sku)) {
                $existing = DB::table('product_variants')->whereRaw('LOWER(sku) = ?', [$skuKey])->first();
            }
            $intent = $existing !== null ? 'update' : 'create';
            $productId = $existing !== null && is_string($existing->product_id) ? $existing->product_id : null;
            if ($errors === []) {
                $errors = array_merge($errors, $this->rowProblems($values, $intent, $productId));
            }
            $report[] = [
                'line' => $row['line'],
                'sku' => $sku,
                'intent' => $intent,
                'valid' => $errors === [],
                'errors' => $errors,
                'product_id' => $productId,
                'values' => $values,
            ];
        }

        return $report;
    }

    /**
     * @param  array<string, int>  $index
     * @param  list<string>  $cells
     * @return array<string, string>
     */
    private function mappedValues(array $index, array $cells): array
    {
        $values = [];
        foreach ($index as $field => $offset) {
            $values[$field] = $cells[$offset] ?? '';
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function rowProblems(array $values, string $intent, ?string $productId): array
    {
        $errors = [];
        $title = $values['title'] ?? '';
        $slug = $values['slug'] ?? '';
        $description = $values['description'] ?? '';
        $status = $values['status'] ?? '';
        $type = ($values['type'] ?? '') === '' ? 'physical' : $values['type'];
        $price = $values['price_amount'] ?? '';
        $imageId = $values['image_asset_id'] ?? '';
        $imageAlt = $values['image_alt'] ?? '';
        if ($title === '' || mb_strlen($title, 'UTF-8') > 200) {
            $errors[] = 'title is required and must be at most 200 characters.';
        }
        if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || in_array($slug, ReservedCatalogSlugs::all(), true)) {
            $errors[] = 'slug is invalid.';
        } else {
            $slugOwner = DB::table('products')->where('slug', $slug)->value('id');
            if (is_string($slugOwner) && ($intent === 'create' || $slugOwner !== $productId)) {
                $errors[] = 'slug is already used.';
            }
        }
        if ($description === '' || mb_strlen($description, 'UTF-8') > 5000) {
            $errors[] = 'description is required and must be at most 5000 characters.';
        }
        if (! in_array($status, ['draft', 'published'], true)) {
            $errors[] = 'status must be draft or published.';
        }
        if (! in_array($type, ['physical', 'digital'], true)) {
            $errors[] = 'type must be physical or digital.';
        }
        if ($intent === 'update' && $productId !== null) {
            $currentType = DB::table('products')->where('id', $productId)->value('type');
            if (is_string($currentType) && $currentType !== $type && ($values['type'] ?? '') !== '') {
                $errors[] = 'Product type cannot be changed.';
            }
        }
        if (preg_match('/^(?:0|[1-9]\d{0,15})$/D', $price) !== 1 || (int) $price > 9007199254740991) {
            $errors[] = 'price_amount must be a whole number of currency units.';
        }
        if ($imageAlt === '' || mb_strlen($imageAlt, 'UTF-8') > 200) {
            $errors[] = 'image_alt is required.';
        }
        if ($intent === 'create' && $imageId === '') {
            $errors[] = 'image_asset_id is required for new products.';
        }
        if ($imageId !== '') {
            if (! Str::isUuid($imageId)) {
                $errors[] = 'image_asset_id is invalid.';
            } else {
                $asset = DB::table('media_assets')->where('id', $imageId)->first();
                if ($asset === null || $asset->kind !== 'public_image' || $asset->status !== 'ready') {
                    $errors[] = 'image_asset_id must be a processed product image.';
                }
            }
        }
        $pathErrors = $this->categoryProblems($values['category_paths'] ?? '');
        $schemaErrors = $this->schemaProblems($values);

        return array_merge($errors, $pathErrors, $schemaErrors);
    }

    /** @return list<string> */
    private function categoryProblems(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $paths = preg_split('/[|;]/', $raw) ?: [];
        $paths = array_values(array_filter(array_map(static fn (string $path): string => trim($path), $paths), static fn (string $path): bool => $path !== ''));
        if (count($paths) > 20) {
            return ['category_paths may list at most 20 categories.'];
        }
        $errors = [];
        foreach ($paths as $path) {
            if (DB::table('categories')->where('path', $path)->doesntExist()) {
                $errors[] = 'category path '.$path.' was not found.';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function schemaProblems(array $values): array
    {
        $attributes = [];
        foreach ($values as $field => $value) {
            if (str_starts_with($field, 'attr:')) {
                $attributes[substr($field, 5)] = $value;
            }
        }
        $schemaKey = $values['schema_key'] ?? '';
        if ($schemaKey === '' && $attributes === []) {
            return [];
        }
        if ($schemaKey === '') {
            return ['Assign a schema before importing typed attributes.'];
        }
        $schema = DB::table('product_schemas')->where('semantic_key', $schemaKey)->first();
        if ($schema === null || ! is_string($schema->current_version_id)) {
            return ['schema_key was not found.'];
        }
        $fields = app(ProductAttributes::class)->fieldsForVersion($schema->current_version_id);
        $allowed = [];
        foreach ($fields as $field) {
            $allowed[$field['key']] = true;
        }
        $errors = [];
        foreach (array_keys($attributes) as $key) {
            if (! isset($allowed[$key])) {
                $errors[] = $key.' is not defined by the selected schema.';
            }
        }
        foreach (app(ProductAttributes::class)->problemsFor($fields, $attributes) as $problem) {
            $errors[] = $problem['key'].' '.$problem['message'];
        }

        return $errors;
    }
}
