<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Catalog\Support\StoreCurrency;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Pricing\Data\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveCoupon
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, code: string, revision: int, status: string}
     */
    public function save(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('pricing.manage')) {
            throw new AuthorizationException;
        }
        foreach (['starts_at', 'ends_at', 'amount', 'percent_numerator', 'percent_denominator', 'max_redemptions', 'max_redemptions_per_customer'] as $empty) {
            if (($input[$empty] ?? null) === '') {
                $input[$empty] = null;
            }
        }
        $data = Validator::make(['coupon' => $input], [
            'coupon' => ['required', 'array:id,version,code,name,mode,amount,percent_numerator,percent_denominator,min_eligible_subtotal,starts_at,ends_at,max_redemptions,max_redemptions_per_customer,status,product_ids,category_ids'],
            'coupon.id' => ['nullable', 'uuid'],
            'coupon.version' => ['required_with:coupon.id', 'integer', 'min:1', 'max:4294967295'],
            'coupon.code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{2,31}$/'],
            'coupon.name' => ['required', 'string', 'max:120'],
            'coupon.mode' => ['required', Rule::in(['fixed', 'percentage', 'free_shipping'])],
            'coupon.amount' => ['nullable', 'integer', 'min:1', 'max:'.Money::MAX_SAFE],
            'coupon.percent_numerator' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'coupon.percent_denominator' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'coupon.min_eligible_subtotal' => ['required', 'integer', 'min:0', 'max:'.Money::MAX_SAFE],
            'coupon.starts_at' => ['nullable', 'date'],
            'coupon.ends_at' => ['nullable', 'date', 'after:coupon.starts_at'],
            'coupon.max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'coupon.max_redemptions_per_customer' => ['nullable', 'integer', 'min:1', 'max:99'],
            'coupon.status' => ['required', Rule::in(['draft', 'active', 'paused'])],
            'coupon.product_ids' => ['sometimes', 'array', 'max:100'],
            'coupon.product_ids.*' => ['uuid', 'distinct:strict'],
            'coupon.category_ids' => ['sometimes', 'array', 'max:100'],
            'coupon.category_ids.*' => ['uuid', 'distinct:strict'],
        ])->validate()['coupon'];
        $code = strtoupper((string) $data['code']);
        $mode = (string) $data['mode'];
        if ($mode === 'fixed' && ! is_numeric($data['amount'] ?? null)) {
            throw ValidationException::withMessages(['coupon.amount' => 'A fixed coupon needs an amount in the store currency.']);
        }
        if ($mode === 'percentage' && ! is_numeric($data['percent_numerator'] ?? null)) {
            throw ValidationException::withMessages(['coupon.percent_numerator' => 'A percentage coupon needs a percent value.']);
        }
        $denominator = $mode === 'percentage' ? (int) ($data['percent_denominator'] ?? 100) : null;
        if ($mode === 'percentage' && ($denominator === null || $denominator < 1 || (int) $data['percent_numerator'] > $denominator)) {
            throw ValidationException::withMessages(['coupon.percent_numerator' => 'The percentage must be between 1 and 100.']);
        }
        $starts = $this->utc($data['starts_at'] ?? null);
        $ends = $this->utc($data['ends_at'] ?? null);
        if ($starts !== null && $ends !== null && $starts >= $ends) {
            throw ValidationException::withMessages(['coupon.ends_at' => 'The end time must be after the start time.']);
        }
        $money = app(StoreCurrency::class)->current();
        try {
            return DB::transaction(function () use ($actor, $data, $code, $mode, $denominator, $starts, $ends, $money): array {
                $now = now();
                $existing = null;
                if (isset($data['id'])) {
                    $existing = DB::table('coupons')->where('id', $data['id'])->lockForUpdate()->first();
                    abort_unless($existing !== null, 404);
                    abort_unless((int) $existing->revision === (int) $data['version'], 409, 'This coupon changed. Reload before saving.');
                    abort_if((int) $existing->revision >= 4294967295, 409);
                    $used = (int) $existing->held_count + (int) $existing->redeemed_count;
                    if (isset($data['max_redemptions']) && (int) $data['max_redemptions'] < $used) {
                        throw ValidationException::withMessages(['coupon.max_redemptions' => 'The redemption cap cannot be below already held or redeemed uses.']);
                    }
                }
                $id = isset($data['id']) ? (string) $data['id'] : (string) Str::uuid();
                $revision = $existing === null ? 1 : ((int) $existing->revision) + 1;
                $row = [
                    'code_normalized' => $code,
                    'name' => $data['name'],
                    'mode' => $mode,
                    'amount' => $mode === 'fixed' ? (int) $data['amount'] : null,
                    'percent_numerator' => $mode === 'percentage' ? (int) $data['percent_numerator'] : null,
                    'percent_denominator' => $denominator,
                    'min_eligible_subtotal' => (int) $data['min_eligible_subtotal'],
                    'currency' => $money['currency'],
                    'exponent' => $money['exponent'],
                    'starts_at' => $starts,
                    'ends_at' => $ends,
                    'max_redemptions' => isset($data['max_redemptions']) ? (int) $data['max_redemptions'] : null,
                    'max_redemptions_per_customer' => isset($data['max_redemptions_per_customer']) ? (int) $data['max_redemptions_per_customer'] : null,
                    'revision' => $revision,
                    'status' => $data['status'],
                    'updated_at' => $now,
                ];
                if ($existing === null) {
                    DB::table('coupons')->insert($row + [
                        'id' => $id, 'held_count' => 0, 'redeemed_count' => 0, 'created_at' => $now,
                    ]);
                } else {
                    DB::table('coupons')->where('id', $id)->update($row);
                    DB::table('coupon_scopes')->where('coupon_id', $id)->delete();
                }
                $this->syncScopes($id, $this->idList($data['product_ids'] ?? null), 'product', $now);
                $this->syncScopes($id, $this->idList($data['category_ids'] ?? null), 'category', $now);
                app(RecordEvent::class)->record('platform.audit.recorded', 'pricing:coupon:'.$id.':'.$revision, [
                    'action' => 'pricing.coupon.saved', 'subject_type' => 'coupon', 'subject_id' => $id,
                    'actor_type' => 'staff', 'actor_id' => $actor->id, 'metadata' => ['version' => $revision],
                ]);

                return ['id' => $id, 'code' => $code, 'revision' => $revision, 'status' => (string) $data['status']];
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (DB::table('coupons')->where('code_normalized', $code)->when(isset($data['id']), fn ($query) => $query->where('id', '!=', $data['id']))->exists()) {
                throw ValidationException::withMessages(['coupon.code' => 'This coupon code is already used.']);
            }
            throw $exception;
        }
    }

    /** @return list<string> */
    private function idList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @param list<string> $ids */
    private function syncScopes(string $couponId, array $ids, string $type, mixed $now): void
    {
        $table = $type === 'product' ? 'products' : 'categories';
        foreach ($ids as $targetId) {
            if (! Str::isUuid($targetId)) {
                throw ValidationException::withMessages(['coupon.'.$type.'_ids' => 'Choose catalog items that exist.']);
            }
            abort_unless(DB::table($table)->where('id', $targetId)->exists(), 422);
            DB::table('coupon_scopes')->insert([
                'id' => (string) Str::uuid(),
                'coupon_id' => $couponId,
                'scope_type' => $type,
                'target_id' => strtolower($targetId),
                'created_at' => $now,
            ]);
        }
    }

    private function utc(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $time = is_string($value) ? new \DateTimeImmutable($value) : null;
        if ($time === null) {
            throw ValidationException::withMessages(['coupon.starts_at' => 'Use a UTC timestamp.']);
        }

        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
