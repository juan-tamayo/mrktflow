<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Pricing\Exceptions\PricingCouponConflict;
use App\Domain\Pricing\Support\PromotionRecords;
use App\Jobs\GenerateCouponBatch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class RequestCouponBatch
{
    public function __construct(private readonly PromotionRecords $records) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, requested_count: int, generated_count: int, status: string}
     */
    public function request(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('pricing.manage')) {
            throw new AuthorizationException;
        }
        $campaignId = is_string($input['campaign_id'] ?? null) ? $input['campaign_id'] : '';
        $intent = is_string($input['source_intent_id'] ?? null) ? $input['source_intent_id'] : '';
        $count = is_numeric($input['requested_count'] ?? null) ? (int) $input['requested_count'] : 0;
        if (! Str::isUuid($campaignId) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_:.-]{7,189}$/', $intent)) {
            throw new InvalidArgumentException('Invalid coupon batch identifiers.');
        }
        if ($count < 1 || $count > 1000) {
            throw ValidationException::withMessages(['batch.requested_count' => 'A batch can create between 1 and 1000 unique codes.']);
        }
        $hash = hash('sha256', json_encode([
            'campaign_id' => $campaignId,
            'requested_count' => $count,
            'purpose' => 'unique_codes',
        ], JSON_THROW_ON_ERROR));

        $batch = DB::transaction(function () use ($actor, $campaignId, $intent, $count, $hash): object {
            $existing = DB::table('coupon_batches')->where('issuer', 'staff')->where('source_intent_id', $intent)->lockForUpdate()->first();
            $campaign = DB::table('promotion_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            abort_unless($campaign !== null, 404);
            if ($this->records->string($campaign, 'kind') !== 'batch') {
                throw ValidationException::withMessages(['campaign' => 'Unique code batches need a batch campaign.']);
            }
            if ($this->records->string($campaign, 'status') !== 'active') {
                throw ValidationException::withMessages(['campaign' => 'Only an active campaign can issue grants.']);
            }
            if ($existing !== null) {
                if ($this->records->string($existing, 'payload_hash') !== $hash) {
                    throw new PricingCouponConflict;
                }

                return $existing;
            }
            $id = (string) Str::uuid();
            $now = now();
            try {
                DB::table('coupon_batches')->insert([
                    'id' => $id,
                    'campaign_id' => $campaignId,
                    'campaign_version' => $this->records->int($campaign, 'revision'),
                    'purpose' => 'unique_codes',
                    'issuer' => 'staff',
                    'source_intent_id' => $intent,
                    'payload_hash' => $hash,
                    'requested_count' => $count,
                    'generated_count' => 0,
                    'status' => 'pending',
                    'expires_at' => $this->records->nullable($campaign, 'ends_at'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                $replay = DB::table('coupon_batches')->where('issuer', 'staff')->where('source_intent_id', $intent)->lockForUpdate()->first();
                if ($replay === null || $this->records->string($replay, 'payload_hash') !== $hash) {
                    throw new PricingCouponConflict;
                }

                return $replay;
            }
            app(RecordEvent::class)->record('platform.audit.recorded', 'pricing:coupon-batch:'.$id, [
                'action' => 'pricing.coupon_batch.requested',
                'subject_type' => 'coupon_batch',
                'subject_id' => $id,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['requested_count' => $count],
            ]);
            $created = DB::table('coupon_batches')->where('id', $id)->first();
            abort_unless($created !== null, 404);

            return $created;
        });

        GenerateCouponBatch::dispatch($this->records->string($batch, 'id'));

        $fresh = DB::table('coupon_batches')->where('id', $this->records->string($batch, 'id'))->first();
        abort_unless($fresh !== null, 404);

        return [
            'id' => $this->records->string($fresh, 'id'),
            'requested_count' => $this->records->int($fresh, 'requested_count'),
            'generated_count' => $this->records->int($fresh, 'generated_count'),
            'status' => $this->records->string($fresh, 'status'),
        ];
    }
}
