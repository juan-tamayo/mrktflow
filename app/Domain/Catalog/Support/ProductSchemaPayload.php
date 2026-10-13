<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProductSchemaPayload
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function fromInput(array $input): array
    {
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

        return $data;
    }
}
