<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Foundation\Http\FormRequest;

final class AttachDigitalAssetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $staff = $this->user('staff');

        return $staff instanceof StaffAccount && $staff->hasPermission('catalog.write') && $staff->hasPermission('media.upload');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version_ids' => ['present', 'array', 'max:20'],
            'version_ids.*' => ['required', 'uuid'],
        ];
    }
}
