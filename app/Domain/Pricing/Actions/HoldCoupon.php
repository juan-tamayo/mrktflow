<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Pricing\Data\CouponHold;
use App\Domain\Pricing\Data\CouponReceipt;
use App\Domain\Pricing\Exceptions\PricingCouponConflict;
use App\Domain\Pricing\Exceptions\PricingCouponExhausted;
use App\Domain\Pricing\Exceptions\PricingCouponRejected;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class HoldCoupon
{
    public function hold(CouponHold $hold): CouponReceipt
    {
        return DB::transaction(function () use ($hold): CouponReceipt {
            $coupon = DB::table('coupons')->where('id', $hold->couponId)->lockForUpdate()->first();
            if ($coupon === null || $this->string($coupon, 'status') !== 'active') {
                throw new PricingCouponRejected;
            }
            if ($this->int($coupon, 'revision') !== $hold->revision) {
                throw new PricingCouponConflict;
            }
            $hash = $this->hash('hold', $hold);
            if ($this->claim($hold->operationKey, $hold->intentId, 'hold', $hash)) {
                return $this->receipt($hold->intentId);
            }
            $max = $this->nullableInt($coupon, 'max_redemptions');
            if ($max !== null && $this->int($coupon, 'held_count') + $this->int($coupon, 'redeemed_count') >= $max) {
                throw new PricingCouponExhausted;
            }
            $perCustomer = $this->nullableInt($coupon, 'max_redemptions_per_customer');
            if ($perCustomer !== null) {
                if ($hold->customerKey === null) {
                    throw new PricingCouponRejected;
                }
                $used = DB::table('coupon_holds')
                    ->where('coupon_id', $hold->couponId)
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
                DB::table('coupon_holds')->insert([
                    'id' => $id,
                    'intent_id' => $hold->intentId,
                    'coupon_id' => $hold->couponId,
                    'revision' => $hold->revision,
                    'customer_key' => $hold->customerKey,
                    'status' => 'active',
                    'expires_at' => $hold->expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new PricingCouponConflict;
            }
            DB::table('coupons')->where('id', $hold->couponId)->increment('held_count');

            return new CouponReceipt($hold->intentId, $id, 'active');
        });
    }

    public function commit(string $intentId, string $operationKey): CouponReceipt
    {
        $this->assertIds($intentId, $operationKey);

        return DB::transaction(function () use ($intentId, $operationKey): CouponReceipt {
            $hold = $this->lockHold($intentId);
            if ($this->claim($operationKey, $intentId, 'commit', $this->mutationHash('commit', $intentId))) {
                return $this->receipt($intentId);
            }
            if ($this->string($hold, 'status') !== 'active') {
                throw new PricingCouponRejected;
            }
            $now = now();
            DB::table('coupon_holds')->where('id', $this->string($hold, 'id'))->update([
                'status' => 'committed',
                'updated_at' => $now,
            ]);
            DB::table('coupons')->where('id', $this->string($hold, 'coupon_id'))->decrement('held_count');
            DB::table('coupons')->where('id', $this->string($hold, 'coupon_id'))->increment('redeemed_count');
            DB::table('coupons')->where('id', $this->string($hold, 'coupon_id'))->update(['updated_at' => $now]);
            DB::table('coupon_redemptions')->insert([
                'id' => (string) Str::uuid(),
                'coupon_id' => $this->string($hold, 'coupon_id'),
                'hold_id' => $this->string($hold, 'id'),
                'intent_id' => $intentId,
                'customer_key' => $this->nullableString($hold, 'customer_key'),
                'revision' => $this->int($hold, 'revision'),
                'committed_at' => $now,
            ]);

            return $this->receipt($intentId);
        });
    }

    public function release(string $intentId, string $operationKey): CouponReceipt
    {
        $this->assertIds($intentId, $operationKey);

        return DB::transaction(function () use ($intentId, $operationKey): CouponReceipt {
            $hold = $this->lockHold($intentId);
            if ($this->claim($operationKey, $intentId, 'release', $this->mutationHash('release', $intentId))) {
                return $this->receipt($intentId);
            }
            if ($this->string($hold, 'status') !== 'active') {
                throw new PricingCouponRejected;
            }
            $now = now();
            DB::table('coupon_holds')->where('id', $this->string($hold, 'id'))->update([
                'status' => 'released',
                'expires_at' => null,
                'updated_at' => $now,
            ]);
            DB::table('coupons')->where('id', $this->string($hold, 'coupon_id'))->decrement('held_count');

            return $this->receipt($intentId);
        });
    }

    private function lockHold(string $intentId): object
    {
        $hold = DB::table('coupon_holds')->where('intent_id', $intentId)->lockForUpdate()->first();
        if ($hold === null) {
            throw new PricingCouponRejected;
        }
        DB::table('coupons')->where('id', $this->string($hold, 'coupon_id'))->lockForUpdate()->first();

        return $hold;
    }

    private function claim(string $operationKey, string $intentId, string $action, string $hash): bool
    {
        try {
            DB::table('coupon_operations')->insert([
                'operation_key' => $operationKey,
                'intent_id' => $intentId,
                'action' => $action,
                'payload_hash' => $hash,
                'created_at' => now(),
            ]);

            return false;
        } catch (UniqueConstraintViolationException) {
            $existing = DB::table('coupon_operations')->where('operation_key', $operationKey)->lockForUpdate()->first();
            if ($existing === null
                || $this->string($existing, 'intent_id') !== $intentId
                || $this->string($existing, 'action') !== $action
                || $this->string($existing, 'payload_hash') !== $hash) {
                throw new PricingCouponConflict;
            }

            return true;
        }
    }

    private function hash(string $action, CouponHold $hold): string
    {
        return hash('sha256', json_encode([
            'action' => $action,
            'intent_id' => $hold->intentId,
            'coupon_id' => $hold->couponId,
            'revision' => $hold->revision,
            'customer_key' => $hold->customerKey,
        ], JSON_THROW_ON_ERROR));
    }

    private function mutationHash(string $action, string $intentId): string
    {
        return hash('sha256', json_encode(['action' => $action, 'intent_id' => $intentId], JSON_THROW_ON_ERROR));
    }

    private function receipt(string $intentId): CouponReceipt
    {
        $hold = DB::table('coupon_holds')->where('intent_id', $intentId)->first();
        if ($hold === null) {
            throw new PricingCouponRejected;
        }

        return new CouponReceipt($intentId, $this->string($hold, 'id'), $this->string($hold, 'status'));
    }

    private function assertIds(string $intentId, string $operationKey): void
    {
        if (! Str::isUuid($intentId) || $intentId !== strtolower($intentId)
            || ! Str::isUuid($operationKey) || $operationKey !== strtolower($operationKey)) {
            throw new InvalidArgumentException('Invalid coupon mutation.');
        }
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableString(object $row, string $key): ?string
    {
        $value = $this->string($row, $key);

        return $value === '' ? null : $value;
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInt(object $row, string $key): ?int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
