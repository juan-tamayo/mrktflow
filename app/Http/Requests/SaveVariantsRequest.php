<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Foundation\Http\FormRequest;

final class SaveVariantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $staff = $this->user('staff');

        return $staff instanceof StaffAccount && $staff->hasPermission('catalog.write');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer'],
            'options' => ['present', 'array', 'max:3'],
            'options.*' => ['required', 'array'],
            'options.*.name' => ['required', 'string', 'max:40'],
            'options.*.values' => ['required', 'array', 'max:20'],
            'options.*.values.*' => ['required', 'string', 'max:40'],
            'variants' => ['required', 'array', 'max:50'],
            'variants.*' => ['required', 'array'],
            'variants.*.id' => ['nullable', 'uuid'],
            'variants.*.sku' => ['required', 'string', 'max:64'],
            'variants.*.price_amount' => ['required', 'integer'],
            'variants.*.is_default' => ['required', 'boolean'],
            'variants.*.available' => ['required', 'boolean'],
            'variants.*.options' => ['present', 'array'],
            'variants.*.image_asset_id' => ['nullable', 'uuid'],
            'variants.*.image_alt' => ['nullable', 'string', 'max:200'],
            'variants.*.weight_grams' => ['nullable', 'integer'],
            'variants.*.length_mm' => ['nullable', 'integer'],
            'variants.*.width_mm' => ['nullable', 'integer'],
            'variants.*.height_mm' => ['nullable', 'integer'],
        ];
    }
}
