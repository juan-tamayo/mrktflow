<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Pricing\Exceptions\PricingCouponConflict;
use App\Domain\Pricing\Support\PromotionRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ImportRecipientGrants
{
    public function __construct(private readonly PromotionRecords $records, private readonly IssuePromotionGrant $grants) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, grant_ids: list<string>, replayed: bool}
     */
    public function import(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('pricing.manage')) {
            throw new AuthorizationException;
        }
        $campaignId = is_string($input['campaign_id'] ?? null) ? $input['campaign_id'] : '';
        $intent = is_string($input['source_intent_id'] ?? null) ? $input['source_intent_id'] : '';
        if (! Str::isUuid($campaignId) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_:.-]{7,189}$/', $intent)) {
            throw new InvalidArgumentException('Invalid promotion grant identifiers.');
        }
        $customers = $this->records->ids(is_array($input['customer_ids'] ?? null) ? $input['customer_ids'] : []);
        if ($customers === [] || count($customers) > 200) {
            throw ValidationException::withMessages(['import.customer_ids' => 'Import between 1 and 200 verified customers.']);
        }
        foreach ($customers as $customerId) {
            if (! Str::isUuid($customerId)) {
                throw ValidationException::withMessages(['import.customer_ids' => 'Import between 1 and 200 verified customers.']);
            }
        }
        $expires = is_string($input['expires_at'] ?? null) && $input['expires_at'] !== '' ? $input['expires_at'] : null;
        $normalized = array_values(array_unique(array_map(strtolower(...), $customers)));
        sort($normalized);
        $hash = hash('sha256', json_encode([
            'campaign_id' => $campaignId,
            'customer_ids' => $normalized,
            'expires_at' => $expires,
            'purpose' => 'recipient_import',
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $campaignId, $intent, $normalized, $expires, $hash): array {
            $existing = DB::table('coupon_batches')->where('issuer', 'import')->where('source_intent_id', $intent)->lockForUpdate()->first();
            $campaign = DB::table('promotion_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            abort_unless($campaign !== null, 404);
            if ($this->records->string($campaign, 'kind') !== 'recipient') {
                throw ValidationException::withMessages(['campaign' => 'Recipient imports need a recipient campaign.']);
            }
            if ($this->records->string($campaign, 'status') !== 'active') {
                throw ValidationException::withMessages(['campaign' => 'Only an active campaign can issue grants.']);
            }
            if ($existing !== null) {
                if ($this->records->string($existing, 'payload_hash') !== $hash) {
                    throw new PricingCouponConflict;
                }

                return [
                    'id' => $this->records->string($existing, 'id'),
                    'grant_ids' => $this->grantIds($this->records->string($existing, 'id')),
                    'replayed' => true,
                ];
            }
            $id = (string) Str::uuid();
            $now = now();
            try {
                DB::table('coupon_batches')->insert([
                    'id' => $id,
                    'campaign_id' => $campaignId,
                    'campaign_version' => $this->records->int($campaign, 'revision'),
                    'purpose' => 'recipient_import',
                    'issuer' => 'import',
                    'source_intent_id' => $intent,
                    'payload_hash' => $hash,
                    'requested_count' => count($normalized),
                    'generated_count' => 0,
                    'status' => 'generating',
                    'expires_at' => $this->records->sqlTime($this->records->instant($expires)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                $replay = DB::table('coupon_batches')->where('issuer', 'import')->where('source_intent_id', $intent)->lockForUpdate()->first();
                if ($replay === null || $this->records->string($replay, 'payload_hash') !== $hash) {
                    throw new PricingCouponConflict;
                }

                return [
                    'id' => $this->records->string($replay, 'id'),
                    'grant_ids' => $this->grantIds($this->records->string($replay, 'id')),
                    'replayed' => true,
                ];
            }
            $grantIds = [];
            foreach ($normalized as $index => $customerId) {
                $grant = $this->grants->issue([
                    'campaign_id' => $campaignId,
                    'issuer' => 'import',
                    'source_intent_id' => $intent.':customer:'.$customerId,
                    'recipient_customer_id' => $customerId,
                    'expires_at' => $expires,
                    'batch_id' => $id,
                ]);
                $grantIds[] = $grant['id'];
                DB::table('coupon_batches')->where('id', $id)->update([
                    'generated_count' => $index + 1,
                    'updated_at' => now(),
                ]);
            }
            DB::table('coupon_batches')->where('id', $id)->update([
                'status' => 'completed',
                'generated_count' => count($grantIds),
                'updated_at' => now(),
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'pricing:coupon-import:'.$id, [
                'action' => 'pricing.coupon_import.issued',
                'subject_type' => 'coupon_batch',
                'subject_id' => $id,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['requested_count' => count($normalized)],
            ]);

            return ['id' => $id, 'grant_ids' => $grantIds, 'replayed' => false];
        });
    }

    /** @return list<string> */
    private function grantIds(string $batchId): array
    {
        $ids = [];
        foreach (DB::table('coupon_grants')->where('batch_id', $batchId)->orderBy('created_at')->orderBy('id')->get() as $row) {
            $ids[] = $this->records->string($row, 'id');
        }

        return $ids;
    }
}
