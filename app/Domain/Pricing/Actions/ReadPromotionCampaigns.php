<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Pricing\Support\PromotionRecords;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReadPromotionCampaigns
{
    public function __construct(private readonly PromotionRecords $records) {}

    /**
     * @return list<array{id: string, name: string, kind: string, status: string, revision: int}>
     */
    public function staffCampaigns(): array
    {
        $rows = [];
        foreach (DB::table('promotion_campaigns')->orderBy('name')->orderBy('id')->get() as $row) {
            $rows[] = [
                'id' => $this->records->string($row, 'id'),
                'name' => $this->records->string($row, 'name'),
                'kind' => $this->records->string($row, 'kind'),
                'status' => $this->records->string($row, 'status'),
                'revision' => $this->records->int($row, 'revision'),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function staffCampaign(string $campaignId): array
    {
        $campaign = DB::table('promotion_campaigns')->where('id', $campaignId)->first();
        if ($campaign === null) {
            throw new NotFoundHttpException;
        }
        $versionId = $this->records->nullable($campaign, 'current_version_id');
        $version = $versionId === null ? null : DB::table('promotion_versions')->where('id', $versionId)->first();
        if ($version === null) {
            throw new NotFoundHttpException;
        }
        $grants = [];
        foreach (DB::table('coupon_grants')->where('campaign_id', $campaignId)->orderBy('created_at')->orderBy('id')->limit(100)->get() as $grant) {
            $coupon = DB::table('coupons')->where('id', $this->records->string($grant, 'coupon_id'))->first();
            $grants[] = [
                'id' => $this->records->string($grant, 'id'),
                'code' => $coupon === null ? '' : $this->records->string($coupon, 'code_normalized'),
                'kind' => $this->records->string($grant, 'kind'),
                'status' => $this->records->string($grant, 'status'),
                'recipient_customer_id' => $this->records->nullable($grant, 'recipient_customer_id'),
                'expires_at' => $this->records->time($grant, 'expires_at'),
            ];
        }
        $batches = [];
        foreach (DB::table('coupon_batches')->where('campaign_id', $campaignId)->orderBy('created_at')->orderBy('id')->get() as $batch) {
            $batches[] = [
                'id' => $this->records->string($batch, 'id'),
                'purpose' => $this->records->string($batch, 'purpose'),
                'status' => $this->records->string($batch, 'status'),
                'requested_count' => $this->records->int($batch, 'requested_count'),
                'generated_count' => $this->records->int($batch, 'generated_count'),
                'source_intent_id' => $this->records->string($batch, 'source_intent_id'),
            ];
        }

        return [
            'id' => $this->records->string($campaign, 'id'),
            'name' => $this->records->string($campaign, 'name'),
            'kind' => $this->records->string($campaign, 'kind'),
            'status' => $this->records->string($campaign, 'status'),
            'version' => $this->records->int($campaign, 'revision'),
            'mode' => $this->records->string($version, 'mode'),
            'amount' => $this->records->nullableInt($version, 'amount'),
            'percent_numerator' => $this->records->nullableInt($version, 'percent_numerator'),
            'percent_denominator' => $this->records->nullableInt($version, 'percent_denominator'),
            'min_eligible_subtotal' => $this->records->int($version, 'min_eligible_subtotal'),
            'starts_at' => $this->records->time($version, 'starts_at'),
            'ends_at' => $this->records->time($version, 'ends_at'),
            'max_redemptions' => $this->records->nullableInt($version, 'max_redemptions'),
            'max_redemptions_per_customer' => $this->records->nullableInt($version, 'max_redemptions_per_customer'),
            'product_ids' => $this->records->idList($version, 'product_ids'),
            'category_ids' => $this->records->idList($version, 'category_ids'),
            'allows_stacking' => $this->records->int($version, 'allows_stacking') === 1,
            'exclude_sale_priced' => $this->records->int($version, 'exclude_sale_priced') === 1,
            'discount_budget' => $this->records->nullableInt($version, 'discount_budget'),
            'max_discount_amount' => $this->records->nullableInt($version, 'max_discount_amount'),
            'first_customer_only' => $this->records->int($version, 'first_customer_only') === 1,
            'grants' => $grants,
            'batches' => $batches,
        ];
    }
}
