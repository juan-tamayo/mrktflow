<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\CustomerAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CustomerAccountAccess
{
    public function lock(CustomerAccount $actor): CustomerAccount
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Customer access locking requires a transaction.');
        }
        $current = CustomerAccount::query()->whereKey($actor->id)->lockForUpdate()->first();
        if ($current === null || ! $current->is_active || $current->session_version !== $actor->session_version || $current->email !== $actor->email) {
            throw new AuthorizationException('Customer access is unavailable.');
        }

        return $current;
    }
}
