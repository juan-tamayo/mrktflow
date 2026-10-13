<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use Illuminate\Validation\ValidationException;

final class ProductImportCsv
{
    /** @return list<string> */
    public static function sampleColumns(): array
    {
        return [
            'sku', 'title', 'slug', 'description', 'status', 'type', 'price_amount',
            'image_asset_id', 'image_alt', 'category_paths', 'schema_key',
        ];
    }

    public static function isFormulaRisk(string $value): bool
    {
        if (str_contains($value, "\0")) {
            return true;
        }
        $trimmed = ltrim($value, " \t");
        if ($trimmed === '') {
            return false;
        }

        return in_array($trimmed[0], ['=', '+', '-', '@', "\t", "\r"], true);
    }

    public static function neutralize(string $value): string
    {
        if (self::isFormulaRisk($value)) {
            return "'".$value;
        }

        return $value;
    }

    public static function normalizeHeader(string $header): string
    {
        $normalized = strtolower(trim($header));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';

        return trim($normalized, '_');
    }

    public static function canonicalTarget(string $header): ?string
    {
        $normalized = self::normalizeHeader($header);
        $aliases = [
            'sku' => 'sku', 'product_sku' => 'sku',
            'title' => 'title', 'name' => 'title', 'product_title' => 'title', 'product_name' => 'title',
            'slug' => 'slug',
            'description' => 'description',
            'status' => 'status',
            'type' => 'type',
            'price' => 'price_amount', 'price_amount' => 'price_amount',
            'image' => 'image_asset_id', 'image_id' => 'image_asset_id', 'image_asset_id' => 'image_asset_id',
            'alt' => 'image_alt', 'image_alt' => 'image_alt',
            'category_paths' => 'category_paths', 'categories' => 'category_paths',
            'schema' => 'schema_key', 'schema_key' => 'schema_key',
        ];
        if (isset($aliases[$normalized])) {
            return $aliases[$normalized];
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/', trim($header)) === 1) {
            return 'attr:'.trim($header);
        }

        return null;
    }

    public static function isAllowedTarget(string $target): bool
    {
        if (in_array($target, self::sampleColumns(), true) || $target === 'ignore') {
            return true;
        }

        return preg_match('/^attr:[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/', $target) === 1;
    }

    /**
     * @return array{headers: list<string>, rows: list<array{line: int, cells: list<string>}>}
     */
    public static function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $handle = fopen('php://temp', 'r+');
        abort_unless($handle !== false, 500);
        fwrite($handle, $contents);
        rewind($handle);
        $headers = fgetcsv($handle);
        if (! is_array($headers) || $headers === [null]) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'Upload a CSV with a header row.']);
        }
        $headerRow = [];
        foreach ($headers as $header) {
            $headerRow[] = is_string($header) ? $header : '';
        }
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($cells === [null]) {
                continue;
            }
            $values = [];
            foreach ($cells as $cell) {
                $values[] = is_string($cell) ? $cell : '';
            }
            $rows[] = ['line' => $line, 'cells' => $values];
        }
        fclose($handle);

        return ['headers' => $headerRow, 'rows' => $rows];
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    public static function suggestMapping(array $headers): array
    {
        $mapping = [];
        foreach ($headers as $header) {
            $target = self::canonicalTarget($header);
            if ($target !== null) {
                $mapping[$header] = $target;
            }
        }

        return $mapping;
    }

    /**
     * @param  list<string>  $fields
     */
    public static function line(array $fields): string
    {
        $escaped = [];
        foreach ($fields as $field) {
            $escaped[] = '"'.str_replace('"', '""', $field).'"';
        }

        return implode(',', $escaped);
    }
}
