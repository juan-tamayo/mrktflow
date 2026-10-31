<?php

declare(strict_types=1);

namespace App\Domain\ShoppingTools\Actions;

use App\Domain\Catalog\Support\ProductAttributes;
use App\Domain\Catalog\Support\VariantAvailability;
use App\Domain\Catalog\Support\VariantOptions;
use App\Domain\CheckoutOrders\Support\CartMoney;
use App\Domain\ShoppingTools\Data\ComparisonView;
use App\Domain\ShoppingTools\Support\UnitConversions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The typed comparison read model (SHOP-05, SHOP-06).
 *
 * A selection names a variant, or a product resolved to its default
 * listed variant exactly as a wishlist save resolves (SHOP-01); the
 * same variant selected twice is one column. Only listed variants of
 * published products can be compared: invalid, unpublished and
 * unlisted selections are removed with a generic notice that carries
 * no catalog facts, and if removals leave fewer than two columns the
 * comparison is refused with the same generic wording, so this
 * surface cannot probe unpublished content.
 *
 * Semantic identity is the catalog's global attribute definition: one
 * semantic key has exactly one type, unit and comparison meaning
 * platform-wide, and schema versions only select and label
 * definitions. A comparison row is therefore a comparable definition
 * from the union of the selections' schema versions — never a label
 * or name match across different keys. A value a product never
 * stored, or an attribute its schema does not define, is an explicit
 * "Not specified" absence, never a zero or an empty equivalent.
 * Numeric cells are normalized through the unit registry to their
 * dimension's canonical base unit before values are compared, and
 * `differs` is decided on those normalized values.
 *
 * Compatibility is the schema versions' comparison group: selections
 * spanning more than one group (a schema-less product is its own
 * null group) expose only the platform basics every product has —
 * price, availability, SKU and type — with the omission stated.
 * The selected variant's own price, SKU, availability and option
 * label are the column's basics; typed attributes are product-level
 * in the catalog model, so no variant attribute override exists to
 * apply.
 */
final class CompareProducts
{
    private const REMOVED_MESSAGE = 'A selected item is no longer available and was removed from the comparison.';

    private const OMITTED_REASON = 'These items belong to different product groups, so only the details every product shares are shown. Specialized specifications are omitted.';

    private const NOT_SPECIFIED = 'Not specified';

    public function __construct(
        private readonly ProductAttributes $attributes,
        private readonly UnitConversions $units,
    ) {}

    /**
     * @param  list<mixed>  $selections  each entry names a variant_id or a product_id
     */
    public function compare(array $selections): ComparisonView
    {
        if (count($selections) < 2 || count($selections) > 4) {
            throw ValidationException::withMessages([
                'selections' => 'Choose two to four items to compare.',
            ]);
        }
        $columns = [];
        $removed = [];
        $seen = [];
        foreach ($selections as $selection) {
            $variantId = is_array($selection) && is_string($selection['variant_id'] ?? null) && $selection['variant_id'] !== ''
                ? $selection['variant_id'] : null;
            $productId = is_array($selection) && is_string($selection['product_id'] ?? null) && $selection['product_id'] !== ''
                ? $selection['product_id'] : null;
            if ($variantId === null && $productId === null) {
                throw ValidationException::withMessages([
                    'selections' => 'Choose an item to compare.',
                ]);
            }
            $resolved = $variantId !== null ? $this->resolveVariant($variantId) : $this->resolveProduct((string) $productId);
            if ($resolved === null) {
                $removed[] = [
                    'selection' => (string) ($variantId ?? $productId),
                    'reason' => 'unavailable',
                    'message' => self::REMOVED_MESSAGE,
                ];

                continue;
            }
            if (isset($seen[$resolved['variant_id']])) {
                continue;
            }
            $seen[$resolved['variant_id']] = true;
            $columns[] = $resolved;
        }
        if (count($columns) < 2) {
            throw ValidationException::withMessages([
                'selections' => 'Choose at least two available items to compare.',
            ]);
        }
        $this->fillAvailability($columns);
        $groups = [];
        foreach ($columns as $column) {
            $groups[$column['comparison_group'] ?? ''] = $column['comparison_group'];
        }
        $compatible = count($groups) <= 1;
        $rows = $this->basicRows($columns);
        $omitted = false;
        $omittedReason = null;
        if ($compatible) {
            foreach ($this->attributeRows($columns) as $row) {
                $rows[] = $row;
            }
        } else {
            foreach ($columns as $column) {
                if ($column['schema_key'] !== null) {
                    $omitted = true;
                }
            }
            $omittedReason = $omitted ? self::OMITTED_REASON : null;
        }
        $groupNames = [];
        foreach ($groups as $group) {
            if ($group !== null) {
                $groupNames[] = $group;
            }
        }
        sort($groupNames);

        return ComparisonView::fromSnapshot([
            'selections' => array_map(fn (array $column): array => $this->columnSnapshot($column), $columns),
            'rows' => $rows,
            'compatible' => $compatible,
            'comparison_groups' => $groupNames,
            'specialized_attributes_omitted' => $omitted,
            'omitted_reason' => $omittedReason,
            'removed' => $removed,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveVariant(string $variantId): ?array
    {
        $row = DB::table('product_variants')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('inventory_balances', 'product_variants.id', '=', 'inventory_balances.variant_id')
            ->where('product_variants.id', $variantId)
            ->where('products.status', 'published')
            ->where('product_variants.available', true)
            ->select($this->selectionColumns())
            ->first();

        return $row === null ? null : $this->columnSeed($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveProduct(string $productId): ?array
    {
        $row = DB::table('product_variants')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('inventory_balances', 'product_variants.id', '=', 'inventory_balances.variant_id')
            ->where('product_variants.product_id', $productId)
            ->where('products.status', 'published')
            ->where('product_variants.available', true)
            ->orderByDesc('product_variants.is_default')
            ->orderBy('product_variants.created_at')
            ->orderBy('product_variants.id')
            ->select($this->selectionColumns())
            ->first();

        return $row === null ? null : $this->columnSeed($row);
    }

    /** @return list<string> */
    private function selectionColumns(): array
    {
        return [
            'product_variants.id as variant_id',
            'product_variants.product_id',
            'product_variants.sku',
            'product_variants.price_amount',
            'product_variants.currency',
            'product_variants.exponent',
            'product_variants.options',
            'product_variants.tracks_inventory',
            'products.title',
            'products.slug',
            'products.type',
            'products.schema_version_id',
            'inventory_balances.on_hand',
            'inventory_balances.reserved',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function columnSeed(object $row): array
    {
        $data = get_object_vars($row);
        $rawOptions = $data['options'] ?? null;
        $decoded = is_array($rawOptions) ? $rawOptions : json_decode(is_string($rawOptions) ? $rawOptions : '[]', true);
        $options = [];
        if (is_array($decoded)) {
            foreach ($decoded as $option) {
                if (is_array($option) && is_string($option['name'] ?? null) && is_string($option['value'] ?? null)) {
                    $options[] = ['name' => $option['name'], 'value' => $option['value']];
                }
            }
        }
        $schemaKey = null;
        $group = null;
        $versionId = is_string($data['schema_version_id'] ?? null) ? $data['schema_version_id'] : null;
        if ($versionId !== null) {
            $schema = DB::table('product_schema_versions')
                ->join('product_schemas', 'product_schema_versions.product_schema_id', '=', 'product_schemas.id')
                ->where('product_schema_versions.id', $versionId)
                ->select(['product_schemas.semantic_key', 'product_schema_versions.comparison_group'])
                ->first();
            if ($schema !== null) {
                $schemaData = get_object_vars($schema);
                $schemaKey = is_string($schemaData['semantic_key'] ?? null) ? $schemaData['semantic_key'] : null;
                $group = is_string($schemaData['comparison_group'] ?? null) ? $schemaData['comparison_group'] : null;
            }
        }
        $variantId = $data['variant_id'] ?? null;
        $productId = $data['product_id'] ?? null;
        $title = $data['title'] ?? null;
        $slug = $data['slug'] ?? null;
        $sku = $data['sku'] ?? null;
        $type = $data['type'] ?? null;
        $currency = $data['currency'] ?? null;

        return [
            'variant_id' => is_string($variantId) ? $variantId : '',
            'product_id' => is_string($productId) ? $productId : '',
            'title' => is_string($title) ? $title : '',
            'slug' => is_string($slug) ? $slug : '',
            'sku' => is_string($sku) ? $sku : '',
            'options' => $options,
            'variant_label' => VariantOptions::label($options),
            'type' => is_string($type) ? $type : '',
            'money' => CartMoney::format(
                is_numeric($data['price_amount'] ?? null) ? (int) $data['price_amount'] : 0,
                is_string($currency) ? $currency : '',
                is_numeric($data['exponent'] ?? null) ? (int) $data['exponent'] : 0,
            ),
            'tracks' => (bool) ($data['tracks_inventory'] ?? false),
            'on_hand' => is_numeric($data['on_hand'] ?? null) ? (int) $data['on_hand'] : null,
            'reserved' => is_numeric($data['reserved'] ?? null) ? (int) $data['reserved'] : null,
            'schema_version_id' => $versionId,
            'schema_key' => $schemaKey,
            'comparison_group' => $group,
            'availability' => VariantAvailability::UNAVAILABLE,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     */
    private function fillAvailability(array &$columns): void
    {
        $digitalIds = [];
        foreach ($columns as $column) {
            if ($column['type'] === 'digital') {
                $digitalIds[] = $column['variant_id'];
            }
        }
        $ready = [];
        if ($digitalIds !== []) {
            foreach (DB::table('variant_digital_assets')
                ->join('digital_asset_versions', 'variant_digital_assets.digital_asset_version_id', '=', 'digital_asset_versions.id')
                ->whereIn('variant_digital_assets.variant_id', $digitalIds)
                ->where('digital_asset_versions.status', 'ready')
                ->distinct()
                ->pluck('variant_digital_assets.variant_id') as $variantId) {
                if (is_string($variantId)) {
                    $ready[$variantId] = true;
                }
            }
        }
        foreach ($columns as $index => $column) {
            $digital = $column['type'] === 'digital';
            $columns[$index]['availability'] = VariantAvailability::state(
                true,
                $column['tracks'],
                $column['on_hand'],
                $column['reserved'],
                $digital,
                $digital && isset($ready[$column['variant_id']]),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $column
     * @return array<string, mixed>
     */
    private function columnSnapshot(array $column): array
    {
        return [
            'variant_id' => $column['variant_id'],
            'product_id' => $column['product_id'],
            'title' => $column['title'],
            'url' => '/products/'.$column['slug'],
            'sku' => $column['sku'],
            'variant_label' => $column['variant_label'],
            'options' => $column['options'],
            'type' => $column['type'],
            'money' => $column['money'],
            'availability' => $column['availability'],
            'available' => VariantAvailability::sellable($column['availability']),
            'schema_key' => $column['schema_key'],
            'comparison_group' => $column['comparison_group'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return list<array<string, mixed>>
     */
    private function basicRows(array $columns): array
    {
        $priceCells = [];
        $availabilityCells = [];
        $skuCells = [];
        $typeCells = [];
        foreach ($columns as $column) {
            $money = $column['money'];
            $priceCells[] = [
                'specified' => true,
                'display' => $money['formatted'],
                'value' => $money['formatted'],
                'unit' => null,
                'normalized_value' => null,
                'normalized_unit' => null,
                'money' => $money,
                '_compare' => $money['amount'].'|'.$money['currency'].'|'.$money['exponent'],
            ];
            $availabilityCells[] = $this->basicCell($column['availability'], [
                VariantAvailability::IN_STOCK => 'In stock',
                VariantAvailability::OUT_OF_STOCK => 'Out of stock',
                VariantAvailability::UNAVAILABLE => 'Unavailable',
            ][$column['availability']]);
            $skuCells[] = $this->basicCell($column['sku'], $column['sku']);
            $typeCells[] = $this->basicCell($column['type'], $column['type']);
        }

        return [
            $this->row('price', 'Price', 'basic', 'money', null, $priceCells),
            $this->row('availability', 'Availability', 'basic', 'availability', null, $availabilityCells),
            $this->row('sku', 'SKU', 'basic', 'string', null, $skuCells),
            $this->row('type', 'Type', 'basic', 'string', null, $typeCells),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function basicCell(string $value, string $display): array
    {
        return [
            'specified' => true,
            'display' => $display,
            'value' => $value,
            'unit' => null,
            'normalized_value' => null,
            'normalized_unit' => null,
            '_compare' => $value,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return list<array<string, mixed>>
     */
    private function attributeRows(array $columns): array
    {
        $definitions = [];
        $fieldsByColumn = [];
        $valuesByColumn = [];
        foreach ($columns as $index => $column) {
            $fields = [];
            if ($column['schema_version_id'] !== null) {
                foreach ($this->attributes->fieldsForVersion($column['schema_version_id']) as $field) {
                    if (! $field['comparable']) {
                        continue;
                    }
                    $fields[$field['key']] = $field;
                    if (! isset($definitions[$field['key']])) {
                        $definitions[$field['key']] = $field;
                    }
                }
            }
            $fieldsByColumn[$index] = $fields;
            $valuesByColumn[$index] = $this->typedValues($column['product_id']);
        }
        $keys = array_keys($definitions);
        sort($keys);
        $rows = [];
        foreach ($keys as $key) {
            $definition = $definitions[$key];
            $cells = [];
            foreach ($columns as $index => $column) {
                $cells[] = $this->attributeCell(
                    $definition,
                    isset($fieldsByColumn[$index][$key]),
                    $valuesByColumn[$index][$key] ?? null,
                );
            }
            $rows[] = $this->row($key, $definition['label'], 'attribute', $definition['type'], $definition['unit'], $cells);
        }

        return $rows;
    }

    /**
     * @return array<string, string|bool>
     */
    private function typedValues(string $productId): array
    {
        $values = [];
        $records = DB::table('attribute_values')
            ->join('attribute_definitions', 'attribute_values.attribute_definition_id', '=', 'attribute_definitions.id')
            ->where('attribute_values.product_id', $productId)
            ->select([
                'attribute_definitions.semantic_key', 'attribute_definitions.type',
                'attribute_values.string_value', 'attribute_values.integer_value', 'attribute_values.decimal_value',
                'attribute_values.boolean_value', 'attribute_values.date_value', 'attribute_values.enum_value',
            ])
            ->get();
        foreach ($records as $record) {
            $data = get_object_vars($record);
            $type = is_string($data['type'] ?? null) ? $data['type'] : '';
            $value = match ($type) {
                'string' => is_string($data['string_value'] ?? null) ? $data['string_value'] : null,
                'integer' => is_numeric($data['integer_value'] ?? null) ? (string) (int) $data['integer_value'] : null,
                'decimal' => is_numeric($data['decimal_value'] ?? null) ? $this->trimDecimal((string) $data['decimal_value']) : null,
                'boolean' => ($data['boolean_value'] ?? null) !== null ? (bool) $data['boolean_value'] : null,
                'date' => is_string($data['date_value'] ?? null) ? $data['date_value'] : null,
                'enum' => is_string($data['enum_value'] ?? null) ? $data['enum_value'] : null,
                default => null,
            };
            $key = $data['semantic_key'] ?? null;
            if ($value !== null && is_string($key)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  array{key: string, type: string, unit: string|null}  $definition
     * @return array<string, mixed>
     */
    private function attributeCell(array $definition, bool $defined, string|bool|null $value): array
    {
        if (! $defined || $value === null) {
            return [
                'specified' => false,
                'display' => self::NOT_SPECIFIED,
                'value' => null,
                'unit' => null,
                'normalized_value' => null,
                'normalized_unit' => null,
                '_compare' => null,
            ];
        }
        $type = $definition['type'];
        $unit = $definition['unit'];
        if (is_bool($value)) {
            return [
                'specified' => true,
                'display' => $value ? 'Yes' : 'No',
                'value' => $value,
                'unit' => null,
                'normalized_value' => null,
                'normalized_unit' => null,
                '_compare' => $value ? '1' : '0',
            ];
        }
        if ($type === 'integer' || $type === 'decimal') {
            $normalizedValue = $value;
            $normalizedUnit = $unit;
            if ($unit !== null) {
                $normalized = $this->units->normalize($value, $unit);
                if ($normalized !== null) {
                    $normalizedValue = $normalized['value'];
                    $normalizedUnit = $normalized['unit'];
                }
            }

            return [
                'specified' => true,
                'display' => $value.($unit !== null ? ' '.$unit : ''),
                'value' => $value,
                'unit' => $unit,
                'normalized_value' => $normalizedValue,
                'normalized_unit' => $normalizedUnit,
                '_compare' => $normalizedValue,
            ];
        }

        return [
            'specified' => true,
            'display' => $value,
            'value' => $value,
            'unit' => null,
            'normalized_value' => null,
            'normalized_unit' => null,
            '_compare' => $value,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cells
     * @return array<string, mixed>
     */
    private function row(string $key, string $label, string $kind, string $type, ?string $unit, array $cells): array
    {
        $seen = [];
        foreach ($cells as $index => $cell) {
            if ($cell['specified'] && $cell['_compare'] !== null) {
                $seen[(string) $cell['_compare']] = true;
            }
            unset($cells[$index]['_compare']);
        }

        return [
            'key' => $key,
            'label' => $label,
            'kind' => $kind,
            'type' => $type,
            'unit' => $unit,
            'differs' => count($seen) > 1,
            'cells' => $cells,
        ];
    }

    private function trimDecimal(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '' ? '0' : $value;
    }
}
