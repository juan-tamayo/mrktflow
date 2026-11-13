<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Catalog\Support\StoreCurrency;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Pricing\Data\Money;
use App\Domain\Pricing\Support\PromotionRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SavePromotionCampaign
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, revision: int, status: string, kind: string}
     */
    public function save(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('pricing.manage')) {
            throw new AuthorizationException;
        }
        foreach (['starts_at', 'ends_at', 'amount', 'percent_numerator', 'percent_denominator', 'max_redemptions', 'max_redemptions_per_customer', 'discount_budget', 'max_discount_amount', 'id', 'version'] as $empty) {
            if (($input[$empty] ?? null) === '') {
                $input[$empty] = null;
            }
        }
        if (($input['id'] ?? null) === null) {
            unset($input['id'], $input['version']);
        }
        $data = Validator::make(['campaign' => $input], [
            'campaign' => ['required', 'array:id,version,name,kind,status,mode,amount,percent_numerator,percent_denominator,min_eligible_subtotal,starts_at,ends_at,max_redemptions,max_redemptions_per_customer,discount_budget,max_discount_amount,first_customer_only,product_ids,category_ids,allows_stacking,exclude_sale_priced'],
            'campaign.id' => ['nullable', 'uuid'],
            'campaign.version' => ['required_with:campaign.id', 'integer', 'min:1', 'max:4294967295'],
            'campaign.name' => ['required', 'string', 'max:120'],
            'campaign.kind' => ['required', Rule::in(['reusable', 'batch', 'recipient', 'automatic'])],
            'campaign.status' => ['required', Rule::in(['draft', 'active', 'paused'])],
            'campaign.mode' => ['required', Rule::in(['fixed', 'percentage', 'free_shipping'])],
            'campaign.amount' => ['nullable', 'integer', 'min:1', 'max:'.Money::MAX_SAFE],
            'campaign.percent_numerator' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'campaign.percent_denominator' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'campaign.min_eligible_subtotal' => ['required', 'integer', 'min:0', 'max:'.Money::MAX_SAFE],
            'campaign.starts_at' => ['nullable', 'date'],
            'campaign.ends_at' => ['nullable', 'date', 'after:campaign.starts_at'],
            'campaign.max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'campaign.max_redemptions_per_customer' => ['nullable', 'integer', 'min:1', 'max:99'],
            'campaign.discount_budget' => ['nullable', 'integer', 'min:1', 'max:'.Money::MAX_SAFE],
            'campaign.max_discount_amount' => ['nullable', 'integer', 'min:1', 'max:'.Money::MAX_SAFE],
            'campaign.first_customer_only' => ['sometimes', 'boolean'],
            'campaign.product_ids' => ['sometimes', 'array', 'max:100'],
            'campaign.product_ids.*' => ['uuid', 'distinct:strict'],
            'campaign.category_ids' => ['sometimes', 'array', 'max:100'],
            'campaign.category_ids.*' => ['uuid', 'distinct:strict'],
            'campaign.allows_stacking' => ['sometimes', 'boolean'],
            'campaign.exclude_sale_priced' => ['sometimes', 'boolean'],
        ])->validate()['campaign'];
        $mode = (string) $data['mode'];
        if ($mode === 'fixed' && ! is_numeric($data['amount'] ?? null)) {
            throw ValidationException::withMessages(['campaign.amount' => 'A fixed offer needs an amount in the store currency.']);
        }
        if ($mode === 'percentage' && ! is_numeric($data['percent_numerator'] ?? null)) {
            throw ValidationException::withMessages(['campaign.percent_numerator' => 'A percentage offer needs a percent value.']);
        }
        $denominator = $mode === 'percentage' ? (int) ($data['percent_denominator'] ?? 100) : null;
        if ($mode === 'percentage' && ($denominator === null || $denominator < 1 || (int) $data['percent_numerator'] > $denominator)) {
            throw ValidationException::withMessages(['campaign.percent_numerator' => 'The percentage must be between 1 and 100.']);
        }
        if (isset($data['discount_budget']) && $mode === 'percentage' && ! is_numeric($data['max_discount_amount'] ?? null)) {
            throw ValidationException::withMessages(['campaign.max_discount_amount' => 'A percent campaign budget needs a finite per-order discount cap.']);
        }
        $records = app(PromotionRecords::class);
        $starts = $records->instant($data['starts_at'] ?? null);
        $ends = $records->instant($data['ends_at'] ?? null);
        if ($starts !== null && $ends !== null && $starts >= $ends) {
            throw ValidationException::withMessages(['campaign.ends_at' => 'The end time must be after the start time.']);
        }
        $money = app(StoreCurrency::class)->current();
        $products = $records->ids(is_array($data['product_ids'] ?? null) ? $data['product_ids'] : []);
        $categories = $records->ids(is_array($data['category_ids'] ?? null) ? $data['category_ids'] : []);
        $this->assertCatalog($products, 'products');
        $this->assertCatalog($categories, 'categories');

        return DB::transaction(function () use ($actor, $data, $mode, $denominator, $starts, $ends, $money, $products, $categories, $records): array {
            $now = now();
            $existing = null;
            if (isset($data['id'])) {
                $existing = DB::table('promotion_campaigns')->where('id', $data['id'])->lockForUpdate()->first();
                abort_unless($existing !== null, 404);
                abort_unless((int) $existing->revision === (int) $data['version'], 409, 'This campaign changed. Reload before saving.');
                abort_if((int) $existing->revision >= 4294967295, 409);
                abort_unless((string) $existing->kind === (string) $data['kind'], 422, 'A campaign kind cannot change after it is created.');
            }
            $id = isset($data['id']) ? (string) $data['id'] : (string) Str::uuid();
            $revision = $existing === null ? 1 : ((int) $existing->revision) + 1;
            $versionId = (string) Str::uuid();
            if ($existing === null) {
                DB::table('promotion_campaigns')->insert([
                    'id' => $id,
                    'name' => $data['name'],
                    'kind' => $data['kind'],
                    'status' => $data['status'],
                    'revision' => $revision,
                    'current_version_id' => $versionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('promotion_campaigns')->where('id', $id)->update([
                    'name' => $data['name'],
                    'status' => $data['status'],
                    'revision' => $revision,
                    'current_version_id' => $versionId,
                    'updated_at' => $now,
                ]);
            }
            DB::table('promotion_versions')->insert([
                'id' => $versionId,
                'campaign_id' => $id,
                'version' => $revision,
                'mode' => $mode,
                'amount' => $mode === 'fixed' ? (int) $data['amount'] : null,
                'percent_numerator' => $mode === 'percentage' ? (int) $data['percent_numerator'] : null,
                'percent_denominator' => $denominator,
                'min_eligible_subtotal' => (int) $data['min_eligible_subtotal'],
                'currency' => $money['currency'],
                'exponent' => $money['exponent'],
                'starts_at' => $records->sqlTime($starts),
                'ends_at' => $records->sqlTime($ends),
                'max_redemptions' => isset($data['max_redemptions']) ? (int) $data['max_redemptions'] : null,
                'max_redemptions_per_customer' => isset($data['max_redemptions_per_customer']) ? (int) $data['max_redemptions_per_customer'] : null,
                'product_ids' => json_encode($products, JSON_THROW_ON_ERROR),
                'category_ids' => json_encode($categories, JSON_THROW_ON_ERROR),
                'allows_stacking' => $this->flag($data['allows_stacking'] ?? false),
                'exclude_sale_priced' => $this->flag($data['exclude_sale_priced'] ?? false),
                'discount_budget' => isset($data['discount_budget']) ? (int) $data['discount_budget'] : null,
                'max_discount_amount' => isset($data['max_discount_amount']) ? (int) $data['max_discount_amount'] : null,
                'first_customer_only' => $this->flag($data['first_customer_only'] ?? false),
                'created_by' => $actor->id,
                'created_at' => $now,
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'pricing:promotion:'.$id.':'.$revision, [
                'action' => 'pricing.promotion.saved',
                'subject_type' => 'promotion_campaign',
                'subject_id' => $id,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['version' => $revision, 'kind' => $data['kind']],
            ]);

            return ['id' => $id, 'revision' => $revision, 'status' => (string) $data['status'], 'kind' => (string) $data['kind']];
        });
    }

    /** @param  list<string>  $ids */
    private function assertCatalog(array $ids, string $table): void
    {
        foreach ($ids as $id) {
            abort_unless(DB::table($table)->where('id', $id)->exists(), 422, 'Choose catalog items that exist.');
        }
    }

    private function flag(mixed $value): int
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' ? 1 : 0;
    }
}
