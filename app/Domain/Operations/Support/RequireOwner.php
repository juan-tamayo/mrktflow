<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Auth\Access\AuthorizationException;

final class RequireOwner
{
    public function assert(StaffAccount $actor): void
    {
        if ($actor->role !== 'owner' || ! $actor->is_active) {
            throw new AuthorizationException;
        }
    }
}
