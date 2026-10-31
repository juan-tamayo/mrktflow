<?php

declare(strict_types=1);

namespace App\Domain\ShoppingTools\Data;

use App\Domain\Catalog\Support\VariantAvailability;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use JsonSerializable;

/**
 * The typed comparison read model (SHOP-05, SHOP-06).
 *
 * One validated snapshot: the compared columns (one per selected
 * variant), the rows they are compared on — platform basics first,
 * then shared semantic attributes — and the comparison's honesty
 * facts: whether the selections were group-compatible, whether
 * specialized attributes were omitted because they were not, and
 * which selections were removed as unavailable. Every row's cells
 * align positionally with the columns; a cell is either specified
 * with a typed value (numeric cells also carry their normalized
 * value in the dimension's canonical base unit) or an explicit
 * "Not specified" absence.
 */
final readonly class ComparisonView implements JsonSerializable
{
    /**
     * @param  list<array{variant_id: string, product_id: string, title: string, url: string, sku: string, variant_label: string, options: list<array{name: string, value: string}>, type: string, money: array{amount: int, currency: string, exponent: int, formatted: string}, availability: string, available: bool, schema_key: ?string, comparison_group: ?string}>  $selections
     * @param  list<array{key: string, label: string, kind: string, type: string, unit: ?string, differs: bool, cells: list<array{specified: bool, display: string, value: string|bool|null, unit: ?string, normalized_value: ?string, normalized_unit: ?string, money?: array{amount: int, currency: string, exponent: int, formatted: string}}>}>  $rows
     * @param  list<string>  $comparisonGroups
     * @param  list<array{selection: string, reason: string, message: string}>  $removed
     */
    private function __construct(
        public array $selections,
        public array $rows,
        public bool $compatible,
        public array $comparisonGroups,
        public bool $specializedAttributesOmitted,
        public ?string $omittedReason,
        public array $removed,
    ) {}

    /** @param array<string, mixed> $snapshot */
    public static function fromSnapshot(array $snapshot): self
    {
        $cellValue = function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && ! is_string($value) && ! is_bool($value)) {
                $fail('Use a string, boolean or null cell value.');
            }
        };
        $data = Validator::make(['comparison' => $snapshot], [
            'comparison' => ['required', 'array'],
            'comparison.selections' => ['required', 'array', 'list', 'min:2', 'max:4'],
            'comparison.selections.*' => ['required', 'array:variant_id,product_id,title,url,sku,variant_label,options,type,money,availability,available,schema_key,comparison_group'],
            'comparison.selections.*.variant_id' => ['required', 'uuid'],
            'comparison.selections.*.product_id' => ['required', 'uuid'],
            'comparison.selections.*.title' => ['required', 'string', 'max:200'],
            'comparison.selections.*.url' => ['required', 'string', 'max:500'],
            'comparison.selections.*.sku' => ['required', 'string', 'max:64'],
            'comparison.selections.*.variant_label' => ['required', 'string', 'max:120'],
            'comparison.selections.*.options' => ['present', 'array', 'list', 'max:3'],
            'comparison.selections.*.options.*' => ['required', 'array:name,value'],
            'comparison.selections.*.options.*.name' => ['required', 'string', 'max:40'],
            'comparison.selections.*.options.*.value' => ['required', 'string', 'max:40'],
            'comparison.selections.*.type' => ['required', 'string', Rule::in(['physical', 'digital'])],
            'comparison.selections.*.money' => ['required', 'array:amount,currency,exponent,formatted'],
            'comparison.selections.*.money.amount' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'comparison.selections.*.money.currency' => ['required', 'string', 'max:3'],
            'comparison.selections.*.money.exponent' => ['required', 'integer', 'min:0', 'max:3'],
            'comparison.selections.*.money.formatted' => ['required', 'string', 'max:32'],
            'comparison.selections.*.availability' => ['required', 'string', Rule::in([VariantAvailability::IN_STOCK, VariantAvailability::OUT_OF_STOCK, VariantAvailability::UNAVAILABLE])],
            'comparison.selections.*.available' => ['required', 'boolean'],
            'comparison.selections.*.schema_key' => ['nullable', 'string', 'max:40'],
            'comparison.selections.*.comparison_group' => ['nullable', 'string', 'max:40'],
            'comparison.rows' => ['required', 'array', 'list', 'max:60'],
            'comparison.rows.*' => ['required', 'array:key,label,kind,type,unit,differs,cells'],
            'comparison.rows.*.key' => ['required', 'string', 'max:80'],
            'comparison.rows.*.label' => ['required', 'string', 'max:120'],
            'comparison.rows.*.kind' => ['required', 'string', Rule::in(['basic', 'attribute'])],
            'comparison.rows.*.type' => ['required', 'string', 'max:16'],
            'comparison.rows.*.unit' => ['nullable', 'string', 'max:16'],
            'comparison.rows.*.differs' => ['required', 'boolean'],
            'comparison.rows.*.cells' => ['required', 'array', 'list', 'min:2', 'max:4'],
            'comparison.rows.*.cells.*' => ['required', 'array'],
            'comparison.rows.*.cells.*.specified' => ['required', 'boolean'],
            'comparison.rows.*.cells.*.display' => ['required', 'string', 'max:500'],
            'comparison.rows.*.cells.*.value' => ['present', $cellValue],
            'comparison.rows.*.cells.*.unit' => ['nullable', 'string', 'max:16'],
            'comparison.rows.*.cells.*.normalized_value' => ['nullable', 'string', 'max:64'],
            'comparison.rows.*.cells.*.normalized_unit' => ['nullable', 'string', 'max:16'],
            'comparison.rows.*.cells.*.money' => ['sometimes', 'array:amount,currency,exponent,formatted'],
            'comparison.rows.*.cells.*.money.amount' => ['required_with:comparison.rows.*.cells.*.money', 'integer', 'min:0'],
            'comparison.rows.*.cells.*.money.currency' => ['required_with:comparison.rows.*.cells.*.money', 'string', 'max:3'],
            'comparison.rows.*.cells.*.money.exponent' => ['required_with:comparison.rows.*.cells.*.money', 'integer', 'min:0', 'max:3'],
            'comparison.rows.*.cells.*.money.formatted' => ['required_with:comparison.rows.*.cells.*.money', 'string', 'max:32'],
            'comparison.compatible' => ['required', 'boolean'],
            'comparison.comparison_groups' => ['present', 'array', 'list', 'max:4'],
            'comparison.comparison_groups.*' => ['string', 'max:40'],
            'comparison.specialized_attributes_omitted' => ['required', 'boolean'],
            'comparison.omitted_reason' => ['nullable', 'string', 'max:300'],
            'comparison.removed' => ['present', 'array', 'list', 'max:3'],
            'comparison.removed.*' => ['required', 'array:selection,reason,message'],
            'comparison.removed.*.selection' => ['required', 'string', 'max:64'],
            'comparison.removed.*.reason' => ['required', 'string', Rule::in(['unavailable'])],
            'comparison.removed.*.message' => ['required', 'string', 'max:200'],
        ])->validate()['comparison'];
        $columns = count($data['selections']);
        foreach ($data['rows'] as $row) {
            if (count($row['cells']) !== $columns) {
                throw new InvalidArgumentException('Comparison rows must align with the compared selections.');
            }
        }

        return new self(
            $data['selections'],
            $data['rows'],
            (bool) $data['compatible'],
            $data['comparison_groups'],
            (bool) $data['specialized_attributes_omitted'],
            $data['omitted_reason'],
            $data['removed'],
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'selections' => $this->selections,
            'rows' => $this->rows,
            'compatible' => $this->compatible,
            'comparison_groups' => $this->comparisonGroups,
            'specialized_attributes_omitted' => $this->specializedAttributesOmitted,
            'omitted_reason' => $this->omittedReason,
            'removed' => $this->removed,
        ];
    }
}
