<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Contracts\MediaUploadAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class StaffMediaUploadAuthorization implements MediaUploadAuthorization
{
    public function authorizeUpload(): string
    {
        Gate::authorize('media.upload');
        $staff = Auth::guard('staff')->user();
        if (! $staff instanceof StaffAccount || ! $staff->is_active) {
            throw new AuthorizationException;
        }

        return $staff->id;
    }
}
