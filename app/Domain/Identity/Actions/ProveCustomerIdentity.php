<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\VerifiedCustomerIdentity;
use App\Domain\Identity\Models\CustomerAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ProveCustomerIdentity
{
    public function prove(CustomerAccount $actor): VerifiedCustomerIdentity
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Verified customer proof requires the ownership transaction.');
        }
        $customer = CustomerAccount::query()->whereKey($actor->id)->lockForUpdate()->first();
        if ($customer === null || ! $customer->is_active || $customer->email_verified_at === null
            || $customer->session_version !== $actor->session_version || $customer->email !== $actor->email) {
            throw new AuthorizationException('Verified customer access is required.');
        }

        return new VerifiedCustomerIdentity($customer->id, $customer->email);
    }
}
