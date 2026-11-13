<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Pricing\Support\PromotionRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ProcessCouponBatch
{
    public function __construct(private readonly PromotionRecords $records, private readonly IssuePromotionGrant $grants) {}

    public function process(string $batchId): void
    {
        if (! Str::isUuid($batchId)) {
            throw new InvalidArgumentException('Invalid coupon batch identifiers.');
        }
        $remaining = 50;
        DB::transaction(function () use ($batchId, &$remaining): void {
            $batch = DB::table('coupon_batches')->where('id', $batchId)->lockForUpdate()->first();
            if ($batch === null) {
                return;
            }
            $status = $this->records->string($batch, 'status');
            if ($status === 'completed' || $status === 'paused') {
                return;
            }
            if ($this->records->string($batch, 'purpose') !== 'unique_codes') {
                return;
            }
            DB::table('coupon_batches')->where('id', $batchId)->update([
                'status' => 'generating',
                'updated_at' => now(),
            ]);
            $generated = $this->records->int($batch, 'generated_count');
            $requested = $this->records->int($batch, 'requested_count');
            $intent = $this->records->string($batch, 'source_intent_id');
            $campaignId = $this->records->string($batch, 'campaign_id');
            while ($generated < $requested && $remaining > 0) {
                $slot = $generated + 1;
                $this->grants->issue([
                    'campaign_id' => $campaignId,
                    'issuer' => 'batch',
                    'source_intent_id' => $intent.':slot:'.$slot,
                    'batch_id' => $batchId,
                ]);
                $generated++;
                $remaining--;
                DB::table('coupon_batches')->where('id', $batchId)->update([
                    'generated_count' => $generated,
                    'updated_at' => now(),
                ]);
            }
            DB::table('coupon_batches')->where('id', $batchId)->update([
                'status' => $generated >= $requested ? 'completed' : 'pending',
                'updated_at' => now(),
            ]);
        });
    }
}
