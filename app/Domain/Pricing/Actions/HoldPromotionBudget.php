<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Pricing\Data\PromotionBudgetHold;
use App\Domain\Pricing\Data\PromotionBudgetReceipt;
use App\Domain\Pricing\Exceptions\PricingCouponConflict;
use App\Domain\Pricing\Exceptions\PricingCouponExhausted;
use App\Domain\Pricing\Exceptions\PricingCouponRejected;
use App\Domain\Pricing\Support\FirstCustomerEligibility;
use App\Domain\Pricing\Support\PromotionRecords;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class HoldPromotionBudget
{
    public function __construct(
        private readonly PromotionRecords $records = new PromotionRecords,
        private readonly FirstCustomerEligibility $firstCustomers = new FirstCustomerEligibility,
    ) {}

    public function hold(PromotionBudgetHold $hold): PromotionBudgetReceipt
    {
        return DB::transaction(function () use ($hold): PromotionBudgetReceipt {
            $campaign = DB::table('promotion_campaigns')->where('id', $hold->campaignId)->lockForUpdate()->first();
            if ($campaign === null) {
                throw new PricingCouponRejected;
            }
            $hash = $this->hash('hold', $hold);
            if ($this->claim($hold->operationKey, $hold->intentId, 'hold', $hash)) {
                return $this->receipt($hold->intentId, $hold->campaignId, $hold->operationKey);
            }
            if ($this->records->int($campaign, 'revision') !== $hold->revision) {
                throw new PricingCouponConflict;
            }
            if ($this->records->string($campaign, 'status') !== 'active') {
                throw new PricingCouponRejected;
            }
            $versionId = $this->records->nullable($campaign, 'current_version_id');
            $version = $versionId === null ? null : DB::table('promotion_versions')->where('id', $versionId)->lockForUpdate()->first();
            if ($version === null) {
                throw new PricingCouponRejected;
            }
            $first = $hold->firstCustomer || $this->records->int($version, 'first_customer_only') === 1;
            if ($first) {
                if (! $this->firstCustomers->verified($hold->customerKey) || $hold->customerKey === null) {
                    throw new PricingCouponRejected;
                }
                if ($this->firstCustomers->hasPurchase($hold->customerKey)
                    || $this->firstCustomers->hasClaim($hold->campaignId, $hold->customerKey, true)) {
                    throw new PricingCouponExhausted;
                }
            }
            if ($hold->couponId !== null) {
                $grant = DB::table('coupon_grants')->where('coupon_id', $hold->couponId)->lockForUpdate()->first();
                if ($grant !== null) {
                    $recipient = $this->records->nullable($grant, 'recipient_customer_id');
                    if ($recipient !== null && $hold->customerKey !== $recipient) {
                        throw new PricingCouponRejected;
                    }
                    if ($this->records->string($grant, 'campaign_id') !== $hold->campaignId) {
                        throw new PricingCouponRejected;
                    }
                }
            }
            $budget = $this->records->nullableInt($version, 'discount_budget');
            if ($budget !== null && $this->records->int($campaign, 'held_amount') + $this->records->int($campaign, 'redeemed_amount') + $hold->amount > $budget) {
                throw new PricingCouponExhausted;
            }
            $max = $this->records->nullableInt($version, 'max_redemptions');
            if ($max !== null) {
                $used = DB::table('promotion_budget_holds')
                    ->where('campaign_id', $hold->campaignId)
                    ->whereIn('status', ['active', 'committed'])
                    ->lockForUpdate()
                    ->count();
                if ($used >= $max) {
                    throw new PricingCouponExhausted;
                }
            }
            $perCustomer = $this->records->nullableInt($version, 'max_redemptions_per_customer');
            if ($perCustomer !== null) {
                if ($hold->customerKey === null) {
                    throw new PricingCouponRejected;
                }
                $used = DB::table('promotion_budget_holds')
                    ->where('campaign_id', $hold->campaignId)
                    ->where('customer_key', $hold->customerKey)
                    ->whereIn('status', ['active', 'committed'])
                    ->lockForUpdate()
                    ->count();
                if ($used >= $perCustomer) {
                    throw new PricingCouponExhausted;
                }
            }
            $id = (string) Str::uuid();
            $now = now();
            try {
                DB::table('promotion_budget_holds')->insert([
                    'id' => $id,
                    'intent_id' => $hold->intentId,
                    'campaign_id' => $hold->campaignId,
                    'revision' => $hold->revision,
                    'coupon_id' => $hold->couponId,
                    'customer_key' => $hold->customerKey,
                    'amount' => $hold->amount,
                    'first_customer' => $first ? 1 : 0,
                    'status' => 'active',
                    'expires_at' => $hold->expiresAt->format('Y-m-d H:i:s'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new PricingCouponConflict;
            }
            DB::table('promotion_campaigns')->where('id', $hold->campaignId)->increment('held_amount', $hold->amount);
            DB::table('promotion_campaigns')->where('id', $hold->campaignId)->update(['updated_at' => $now]);

            return new PromotionBudgetReceipt($hold->intentId, $id, 'active', $hold->operationKey);
        });
    }

    public function commit(string $intentId, string $operationKey): ?PromotionBudgetReceipt
    {
        $this->assertIds($intentId, $operationKey);

        return DB::transaction(function () use ($intentId, $operationKey): ?PromotionBudgetReceipt {
            $holds = DB::table('promotion_budget_holds')->where('intent_id', $intentId)->orderBy('campaign_id')->lockForUpdate()->get();
            if ($holds->isEmpty()) {
                return null;
            }
            if ($this->claim($operationKey, $intentId, 'commit', $this->mutationHash('commit', $intentId))) {
                return $this->firstReceipt($holds, $intentId, $operationKey);
            }
            $now = now();
            foreach ($holds as $hold) {
                DB::table('promotion_campaigns')->where('id', $this->records->string($hold, 'campaign_id'))->lockForUpdate()->first();
                if ($this->records->string($hold, 'status') !== 'active') {
                    throw new PricingCouponRejected;
                }
                DB::table('promotion_budget_holds')->where('id', $this->records->string($hold, 'id'))->update([
                    'status' => 'committed',
                    'updated_at' => $now,
                ]);
                $amount = $this->records->int($hold, 'amount');
                DB::table('promotion_campaigns')->where('id', $this->records->string($hold, 'campaign_id'))->decrement('held_amount', $amount);
                DB::table('promotion_campaigns')->where('id', $this->records->string($hold, 'campaign_id'))->increment('redeemed_amount', $amount);
                DB::table('promotion_campaigns')->where('id', $this->records->string($hold, 'campaign_id'))->update(['updated_at' => $now]);
            }

            return $this->firstReceipt($holds, $intentId, $operationKey);
        });
    }

    public function rehold(string $intentId, string $operationKey): ?PromotionBudgetReceipt
    {
        $this->assertIds($intentId, $operationKey);

        return DB::transaction(function () use ($intentId, $operationKey): ?PromotionBudgetReceipt {
            $holds = DB::table('promotion_budget_holds')->where('intent_id', $intentId)->orderBy('campaign_id')->lockForUpdate()->get();
            if ($holds->isEmpty()) {
                return null;
            }
            if ($this->claim($operationKey, $intentId, 'hold', $this->mutationHash('rehold', $intentId))) {
                return $this->firstReceipt($holds, $intentId, $operationKey);
            }
            $now = now();
            foreach ($holds as $hold) {
                $status = $this->records->string($hold, 'status');
                if (in_array($status, ['active', 'committed'], true)) {
                    continue;
                }
                if ($status !== 'released') {
                    throw new PricingCouponRejected;
                }
                $campaignId = $this->records->string($hold, 'campaign_id');
                $campaign = DB::table('promotion_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
                if ($campaign === null) {
                    throw new PricingCouponRejected;
                }
                $versionId = $this->records->nullable($campaign, 'current_version_id');
                $version = $versionId === null ? null : DB::table('promotion_versions')->where('id', $versionId)->lockForUpdate()->first();
                if ($version === null) {
                    throw new PricingCouponRejected;
                }
                $amount = $this->records->int($hold, 'amount');
                $budget = $this->records->nullableInt($version, 'discount_budget');
                if ($budget !== null && $this->records->int($campaign, 'held_amount') + $this->records->int($campaign, 'redeemed_amount') + $amount > $budget) {
                    throw new PricingCouponExhausted;
                }
                if ($this->records->int($hold, 'first_customer') === 1) {
                    $customer = $this->records->nullable($hold, 'customer_key');
                    if ($customer === null || $this->firstCustomers->hasClaim($campaignId, $customer, true)) {
                        throw new PricingCouponExhausted;
                    }
                }
                DB::table('promotion_budget_holds')->where('id', $this->records->string($hold, 'id'))->update([
                    'status' => 'active',
                    'expires_at' => $now->copy()->utc()->addMinutes(30),
                    'updated_at' => $now,
                ]);
                DB::table('promotion_campaigns')->where('id', $campaignId)->increment('held_amount', $amount);
                DB::table('promotion_campaigns')->where('id', $campaignId)->update(['updated_at' => $now]);
            }

            return $this->firstReceipt($holds, $intentId, $operationKey);
        });
    }

    public function release(string $intentId, string $operationKey): ?PromotionBudgetReceipt
    {
        $this->assertIds($intentId, $operationKey);

        return DB::transaction(function () use ($intentId, $operationKey): ?PromotionBudgetReceipt {
            $holds = DB::table('promotion_budget_holds')->where('intent_id', $intentId)->orderBy('campaign_id')->lockForUpdate()->get();
            if ($holds->isEmpty()) {
                return null;
            }
            if ($this->claim($operationKey, $intentId, 'release', $this->mutationHash('release', $intentId))) {
                return $this->firstReceipt($holds, $intentId, $operationKey);
            }
            $now = now();
            foreach ($holds as $hold) {
                DB::table('promotion_campaigns')->where('id', $this->records->string($hold, 'campaign_id'))->lockForUpdate()->first();
                if ($this->records->string($hold, 'status') !== 'active') {
                    throw new PricingCouponRejected;
                }
                DB::table('promotion_budget_holds')->where('id', $this->records->string($hold, 'id'))->update([
                    'status' => 'released',
                    'expires_at' => null,
                    'updated_at' => $now,
                ]);
                DB::table('promotion_campaigns')->where('id', $this->records->string($hold, 'campaign_id'))
                    ->decrement('held_amount', $this->records->int($hold, 'amount'));
            }

            return $this->firstReceipt($holds, $intentId, $operationKey);
        });
    }

    private function claim(string $operationKey, string $intentId, string $action, string $hash): bool
    {
        try {
            DB::table('promotion_budget_operations')->insert([
                'operation_key' => $operationKey,
                'intent_id' => $intentId,
                'action' => $action,
                'payload_hash' => $hash,
                'created_at' => now(),
            ]);

            return false;
        } catch (UniqueConstraintViolationException) {
            $existing = DB::table('promotion_budget_operations')->where('operation_key', $operationKey)->lockForUpdate()->first();
            if ($existing === null
                || $this->records->string($existing, 'intent_id') !== $intentId
                || $this->records->string($existing, 'action') !== $action
                || $this->records->string($existing, 'payload_hash') !== $hash) {
                throw new PricingCouponConflict;
            }

            return true;
        }
    }

    private function hash(string $action, PromotionBudgetHold $hold): string
    {
        return hash('sha256', json_encode([
            'action' => $action,
            'intent_id' => $hold->intentId,
            'campaign_id' => $hold->campaignId,
            'revision' => $hold->revision,
            'amount' => $hold->amount,
            'coupon_id' => $hold->couponId,
            'customer_key' => $hold->customerKey,
            'first_customer' => $hold->firstCustomer,
        ], JSON_THROW_ON_ERROR));
    }

    private function mutationHash(string $action, string $intentId): string
    {
        return hash('sha256', json_encode(['action' => $action, 'intent_id' => $intentId], JSON_THROW_ON_ERROR));
    }

    /** @param  Collection<int, \stdClass>  $holds */
    private function firstReceipt(Collection $holds, string $intentId, string $operationKey): PromotionBudgetReceipt
    {
        foreach ($holds as $first) {
            return $this->receipt($intentId, $this->records->string($first, 'campaign_id'), $operationKey);
        }

        throw new PricingCouponRejected;
    }

    private function receipt(string $intentId, string $campaignId, string $operationKey): PromotionBudgetReceipt
    {
        $hold = DB::table('promotion_budget_holds')->where('intent_id', $intentId)->where('campaign_id', $campaignId)->first();
        if ($hold === null) {
            throw new PricingCouponRejected;
        }

        return new PromotionBudgetReceipt(
            $intentId,
            $this->records->string($hold, 'id'),
            $this->records->string($hold, 'status'),
            $operationKey,
        );
    }

    private function assertIds(string $intentId, string $operationKey): void
    {
        if (! Str::isUuid($intentId) || $intentId !== strtolower($intentId)
            || ! Str::isUuid($operationKey) || $operationKey !== strtolower($operationKey)) {
            throw new InvalidArgumentException('Invalid promotion budget mutation.');
        }
    }
}
