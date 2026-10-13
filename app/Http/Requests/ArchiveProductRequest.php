<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Foundation\Http\FormRequest;

final class ArchiveProductRequest extends FormRequest
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
            'version' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ];
    }
}
