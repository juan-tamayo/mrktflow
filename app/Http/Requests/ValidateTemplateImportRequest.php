<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Foundation\Http\FormRequest;

final class ValidateTemplateImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $staff = $this->user('staff');

        return $staff instanceof StaffAccount && $staff->hasPermission('content.write');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required_without:import_id', 'file'],
            'import_id' => ['required_without:file', 'nullable', 'uuid'],
            'references' => ['sometimes', 'array'],
            'references.products' => ['sometimes', 'array'],
            'references.products.*' => ['uuid'],
            'references.categories' => ['sometimes', 'array'],
            'references.categories.*' => ['uuid'],
        ];
    }
}
