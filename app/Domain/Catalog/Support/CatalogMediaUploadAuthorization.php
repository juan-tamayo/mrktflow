<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Contracts\MediaUploadAuthorization;
use Illuminate\Auth\Access\AuthorizationException;

final class CatalogMediaUploadAuthorization implements MediaUploadAuthorization
{
    public function __construct(private readonly StaffAccount $staff) {}

    public function authorizeUpload(): string
    {
        if (! $this->staff->is_active || ! $this->staff->hasPermission('media.upload')) {
            throw new AuthorizationException;
        }

        return $this->staff->id;
    }
}
