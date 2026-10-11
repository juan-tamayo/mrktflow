<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Support\StaffPermissions;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class StaffAccount extends Authenticatable
{
    use HasUuids;

    protected $table = 'staff_accounts';

    protected $hidden = ['password', 'remember_token', 'permissions', 'session_version'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_active' => 'boolean', 'session_version' => 'integer', 'email_verified_at' => 'immutable_datetime'];
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->getAttribute('permissions');

        return $this->is_active && in_array($permission, StaffPermissions::all(), true)
            && is_array($permissions) && in_array($permission, $permissions, true);
    }
}
