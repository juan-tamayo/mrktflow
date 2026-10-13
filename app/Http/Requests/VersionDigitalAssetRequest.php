<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Foundation\Http\FormRequest;

final class VersionDigitalAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $staff = $this->user('staff');

        return $staff instanceof StaffAccount
            && $staff->hasPermission('catalog.write')
            && $staff->hasPermission('media.upload');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'digital_asset_id' => ['nullable', 'uuid'],
            'label' => ['required', 'string', 'max:120'],
            'file' => ['required', 'file'],
        ];
    }
}
