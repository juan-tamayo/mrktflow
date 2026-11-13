<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FirstCustomerEligibility
{
    public function verified(?string $customerId): bool
    {
        if ($customerId === null || ! Str::isUuid($customerId) || $customerId !== strtolower($customerId)) {
            return false;
        }
        $customer = DB::table('customer_accounts')->where('id', $customerId)->first();
        if ($customer === null) {
            return false;
        }
        $vars = get_object_vars($customer);
        $verified = $vars['email_verified_at'] ?? null;
        $active = $vars['is_active'] ?? null;

        return $verified !== null && $verified !== '' && ($active === true || $active === 1 || $active === '1');
    }

    public function hasPurchase(string $customerId): bool
    {
        return DB::table('orders')
            ->where('customer_id', $customerId)
            ->where(function (Builder $query): void {
                $query->whereIn('financial_status', ['paid', 'partially_refunded', 'refunded', 'unknown'])
                    ->orWhereIn('status', ['confirmed', 'completed', 'on_hold']);
            })
            ->exists();
    }

    public function hasClaim(string $campaignId, string $customerId, bool $lock = false): bool
    {
        $query = DB::table('promotion_budget_holds')
            ->where('campaign_id', $campaignId)
            ->where('customer_key', $customerId)
            ->where('first_customer', true)
            ->whereIn('status', ['active', 'committed']);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->exists();
    }

    public function eligible(?string $customerId, ?string $campaignId = null): bool
    {
        if (! $this->verified($customerId) || $customerId === null) {
            return false;
        }
        if ($this->hasPurchase($customerId)) {
            return false;
        }
        if ($campaignId !== null && $this->hasClaim($campaignId, $customerId)) {
            return false;
        }

        return true;
    }
}
