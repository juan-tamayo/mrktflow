<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** @property int $profile_version */
final class CustomerAccount extends Authenticatable
{
    use HasUuids;

    protected $table = 'customer_accounts';

    protected $hidden = ['password', 'remember_token', 'session_version', 'profile_version'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'session_version' => 'integer', 'profile_version' => 'integer', 'email_verified_at' => 'immutable_datetime'];
    }
}
