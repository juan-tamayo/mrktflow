<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Pricing\Contracts\CouponCodeGenerator;
use App\Domain\Pricing\Exceptions\PricingCouponConflict;
use App\Domain\Pricing\Support\PromotionRecords;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class IssuePromotionGrant
{
    public function __construct(private readonly PromotionRecords $records, private readonly CouponCodeGenerator $codes) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, code: string, coupon_id: string, status: string}
     */
    public function issue(array $input): array
    {
        $issuer = is_string($input['issuer'] ?? null) ? $input['issuer'] : '';
        $intent = is_string($input['source_intent_id'] ?? null) ? $input['source_intent_id'] : '';
        $campaignId = is_string($input['campaign_id'] ?? null) ? $input['campaign_id'] : '';
        if (! in_array($issuer, ['staff', 'import', 'recovery', 'referral', 'batch'], true)
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_:.-]{7,189}$/', $intent)
            || ! Str::isUuid($campaignId)) {
            throw new InvalidArgumentException('Invalid promotion grant identifiers.');
        }
        $recipient = is_string($input['recipient_customer_id'] ?? null) && $input['recipient_customer_id'] !== ''
            ? strtolower($input['recipient_customer_id'])
            : null;
        if ($recipient !== null && ! Str::isUuid($recipient)) {
            throw ValidationException::withMessages(['grant.recipient_customer_id' => 'Choose a verified customer.']);
        }
        $code = is_string($input['code'] ?? null) && $input['code'] !== '' ? strtoupper(trim($input['code'])) : null;
        if ($code !== null && ! preg_match('/^[A-Z0-9][A-Z0-9_-]{2,31}$/', $code)) {
            throw ValidationException::withMessages(['grant.code' => 'Use a 3–32 character coupon code.']);
        }
        $expires = $this->records->instant($input['expires_at'] ?? null);
        $batchId = is_string($input['batch_id'] ?? null) && $input['batch_id'] !== '' ? $input['batch_id'] : null;
        if ($batchId !== null && ! Str::isUuid($batchId)) {
            throw new InvalidArgumentException('Invalid promotion grant identifiers.');
        }

        return DB::transaction(function () use ($issuer, $intent, $campaignId, $recipient, $code, $expires, $batchId): array {
            $existing = DB::table('coupon_grants')->where('issuer', $issuer)->where('source_intent_id', $intent)->lockForUpdate()->first();
            $campaign = DB::table('promotion_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            abort_unless($campaign !== null, 404);
            if ($this->records->string($campaign, 'status') !== 'active') {
                throw ValidationException::withMessages(['campaign' => 'Only an active campaign can issue grants.']);
            }
            $kind = $this->records->string($campaign, 'kind');
            if ($kind === 'automatic') {
                throw ValidationException::withMessages(['campaign' => 'Automatic offers do not issue coupon codes.']);
            }
            if ($recipient !== null && $kind !== 'recipient') {
                throw ValidationException::withMessages(['grant.recipient_customer_id' => 'Recipient grants need a recipient campaign.']);
            }
            if ($recipient === null && $kind === 'recipient' && $issuer !== 'batch') {
                throw ValidationException::withMessages(['grant.recipient_customer_id' => 'Recipient grants need a verified customer.']);
            }
            if ($kind === 'reusable' && $code === null) {
                throw ValidationException::withMessages(['grant.code' => 'Reusable campaigns need a public code.']);
            }
            if ($kind !== 'reusable' && $code !== null) {
                throw ValidationException::withMessages(['grant.code' => 'Generated grants assign an unpredictable code.']);
            }
            if ($recipient !== null) {
                $customer = DB::table('customer_accounts')->where('id', $recipient)->lockForUpdate()->first();
                $active = $customer === null ? null : (get_object_vars($customer)['is_active'] ?? null);
                if ($customer === null || $this->records->string($customer, 'email_verified_at') === '' || ($active !== true && $active !== 1 && $active !== '1')) {
                    throw ValidationException::withMessages(['grant.recipient_customer_id' => 'Recipient grants need a verified customer.']);
                }
            }
            $versionId = $this->records->nullable($campaign, 'current_version_id');
            abort_unless($versionId !== null, 404);
            $version = DB::table('promotion_versions')->where('id', $versionId)->first();
            abort_unless($version !== null, 404);
            $hash = $this->hash($issuer, $intent, $campaignId, $kind, $recipient, $code, $expires?->format('c'), $batchId);
            if ($existing !== null) {
                if ($this->records->string($existing, 'payload_hash') !== $hash) {
                    throw new PricingCouponConflict;
                }

                return $this->receipt($existing);
            }
            $couponExpires = $expires ?? $this->records->instant($this->records->nullable($version, 'ends_at'));
            $unique = $kind !== 'reusable';
            $couponId = null;
            try {
                $couponId = $this->createCoupon($version, $unique, $code, $couponExpires, $kind === 'recipient' ? $recipient : null);
                $id = (string) Str::uuid();
                $now = now();
                DB::table('coupon_grants')->insert([
                    'id' => $id,
                    'campaign_id' => $campaignId,
                    'campaign_version' => $this->records->int($version, 'version'),
                    'batch_id' => $batchId,
                    'coupon_id' => $couponId,
                    'issuer' => $issuer,
                    'source_intent_id' => $intent,
                    'payload_hash' => $hash,
                    'kind' => $kind === 'batch' ? 'batch' : ($recipient !== null ? 'recipient' : 'reusable'),
                    'recipient_customer_id' => $recipient,
                    'expires_at' => $this->records->sqlTime($couponExpires),
                    'status' => 'issued',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($couponId !== null && ! DB::table('coupon_grants')->where('coupon_id', $couponId)->exists()) {
                    DB::table('coupon_scopes')->where('coupon_id', $couponId)->delete();
                    DB::table('coupons')->where('id', $couponId)->delete();
                }
                $replay = DB::table('coupon_grants')->where('issuer', $issuer)->where('source_intent_id', $intent)->lockForUpdate()->first();
                if ($replay !== null && $this->records->string($replay, 'payload_hash') === $hash) {
                    return $this->receipt($replay);
                }
                if ($code !== null && DB::table('coupons')->where('code_normalized', $code)->exists()) {
                    throw ValidationException::withMessages(['grant.code' => 'This coupon code is already used.']);
                }
                throw $exception;
            }

            return $this->receipt(DB::table('coupon_grants')->where('issuer', $issuer)->where('source_intent_id', $intent)->first());
        });
    }

    private function uniqueCode(): string
    {
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $code = strtoupper($this->codes->next());
            if (! preg_match('/^[A-Z0-9][A-Z0-9_-]{2,31}$/', $code)) {
                continue;
            }
            if (! DB::table('coupons')->where('code_normalized', $code)->exists()) {
                return $code;
            }
        }

        throw ValidationException::withMessages(['grant.code' => 'A unique coupon code could not be generated.']);
    }

    private function createCoupon(object $version, bool $unique, ?string $code, ?DateTimeImmutable $expires, ?string $recipient): string
    {
        for ($attempt = 0; $attempt < 16; $attempt++) {
            try {
                $assigned = $unique ? $this->uniqueCode() : (string) $code;

                return $this->insertCoupon($version, $assigned, $unique, $expires, $recipient);
            } catch (UniqueConstraintViolationException $exception) {
                if (! $unique) {
                    throw $exception;
                }
            }
        }

        throw ValidationException::withMessages(['grant.code' => 'A unique coupon code could not be generated.']);
    }

    private function insertCoupon(object $version, string $code, bool $unique, ?DateTimeImmutable $expires, ?string $recipient): string
    {
        $id = (string) Str::uuid();
        $now = now();
        $mode = $this->records->string($version, 'mode');
        $perCustomer = $unique ? 1 : $this->records->nullableInt($version, 'max_redemptions_per_customer');
        $max = $unique ? 1 : $this->records->nullableInt($version, 'max_redemptions');
        if ($recipient !== null) {
            $perCustomer = 1;
            $max = 1;
        }
        DB::table('coupons')->insert([
            'id' => $id,
            'code_normalized' => $code,
            'name' => $code,
            'mode' => $mode,
            'amount' => $mode === 'fixed' ? $this->records->nullableInt($version, 'amount') : null,
            'percent_numerator' => $mode === 'percentage' ? $this->records->nullableInt($version, 'percent_numerator') : null,
            'percent_denominator' => $mode === 'percentage' ? $this->records->nullableInt($version, 'percent_denominator') : null,
            'min_eligible_subtotal' => $this->records->int($version, 'min_eligible_subtotal'),
            'currency' => $this->records->string($version, 'currency'),
            'exponent' => $this->records->int($version, 'exponent'),
            'starts_at' => $this->records->nullable($version, 'starts_at'),
            'ends_at' => $this->records->sqlTime($expires) ?? $this->records->nullable($version, 'ends_at'),
            'max_redemptions' => $max,
            'max_redemptions_per_customer' => $perCustomer,
            'held_count' => 0,
            'redeemed_count' => 0,
            'revision' => 1,
            'status' => 'active',
            'allows_stacking' => $this->records->int($version, 'allows_stacking') === 1 ? 1 : 0,
            'exclude_sale_priced' => $this->records->int($version, 'exclude_sale_priced') === 1 ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach (['product' => $this->records->idList($version, 'product_ids'), 'category' => $this->records->idList($version, 'category_ids')] as $type => $ids) {
            foreach ($ids as $targetId) {
                DB::table('coupon_scopes')->insert([
                    'id' => (string) Str::uuid(),
                    'coupon_id' => $id,
                    'scope_type' => $type,
                    'target_id' => $targetId,
                    'created_at' => $now,
                ]);
            }
        }

        return $id;
    }

    /** @return array{id: string, code: string, coupon_id: string, status: string} */
    private function receipt(?object $grant): array
    {
        if ($grant === null) {
            throw new PricingCouponConflict;
        }
        $coupon = DB::table('coupons')->where('id', $this->records->string($grant, 'coupon_id'))->first();
        if ($coupon === null) {
            throw new PricingCouponConflict;
        }

        return [
            'id' => $this->records->string($grant, 'id'),
            'code' => $this->records->string($coupon, 'code_normalized'),
            'coupon_id' => $this->records->string($grant, 'coupon_id'),
            'status' => $this->records->string($grant, 'status'),
        ];
    }

    private function hash(string $issuer, string $intent, string $campaignId, string $kind, ?string $recipient, ?string $code, ?string $expires, ?string $batchId): string
    {
        return hash('sha256', json_encode([
            'issuer' => $issuer,
            'source_intent_id' => $intent,
            'campaign_id' => $campaignId,
            'kind' => $kind,
            'recipient_customer_id' => $recipient,
            'code' => $code,
            'expires_at' => $expires,
            'batch_id' => $batchId,
        ], JSON_THROW_ON_ERROR));
    }
}
