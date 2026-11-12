<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Marketing\Support\CampaignRecords;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveCampaign
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, revision: int, status: string}
     */
    public function save(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('marketing.write')) {
            throw new AuthorizationException;
        }
        foreach (['offer_reference', 'id', 'version'] as $empty) {
            if (($input[$empty] ?? null) === '') {
                $input[$empty] = null;
            }
        }
        $audience = is_array($input['audience'] ?? null) ? $input['audience'] : [];
        if (! array_key_exists('category_ids', $audience) || $audience['category_ids'] === null) {
            $audience['category_ids'] = [];
        }
        foreach (['purchased_after', 'purchased_before', 'previous_campaign_id'] as $empty) {
            if (($audience[$empty] ?? null) === '') {
                $audience[$empty] = null;
            }
        }
        $input['audience'] = $audience;
        $data = Validator::make(['campaign' => $input], [
            'campaign' => ['required', 'array:id,version,name,subject,body_text,purpose,audience,schedule,caps,offer_reference'],
            'campaign.id' => ['nullable', 'uuid'],
            'campaign.version' => ['required_with:campaign.id', 'integer', 'min:1', 'max:4294967295'],
            'campaign.name' => ['required', 'string', 'max:120'],
            'campaign.subject' => ['required', 'string', 'max:180'],
            'campaign.body_text' => ['required', 'string', 'max:8000'],
            'campaign.purpose' => ['required', Rule::in(['newsletter'])],
            'campaign.audience' => ['required', 'array:customer_status,category_ids,purchased_after,purchased_before,previous_campaign_id,previous_campaign_mode'],
            'campaign.audience.customer_status' => ['required', Rule::in(['any', 'customer', 'guest', 'new_customer'])],
            'campaign.audience.category_ids' => ['sometimes', 'array', 'max:50'],
            'campaign.audience.category_ids.*' => ['uuid', 'distinct:strict'],
            'campaign.audience.purchased_after' => ['nullable', 'date'],
            'campaign.audience.purchased_before' => ['nullable', 'date'],
            'campaign.audience.previous_campaign_id' => ['nullable', 'uuid'],
            'campaign.audience.previous_campaign_mode' => ['required', Rule::in(['any', 'included', 'excluded'])],
            'campaign.schedule' => ['required', 'array:send_at'],
            'campaign.schedule.send_at' => ['required', 'date'],
            'campaign.caps' => ['required', 'array:max_recipients,per_contact_24h,per_contact_7d'],
            'campaign.caps.max_recipients' => ['required', 'integer', 'min:1', 'max:100000'],
            'campaign.caps.per_contact_24h' => ['required', 'integer', 'min:1', 'max:24'],
            'campaign.caps.per_contact_7d' => ['required', 'integer', 'min:1', 'max:21'],
            'campaign.offer_reference' => ['nullable', 'uuid'],
        ])->validate()['campaign'];
        $this->plainText((string) $data['subject'], 'campaign.subject');
        $this->plainText((string) $data['body_text'], 'campaign.body_text');
        $records = app(CampaignRecords::class);
        $sendAt = $records->instant($data['schedule']['send_at'] ?? null);
        if ($sendAt === null) {
            throw ValidationException::withMessages(['campaign.schedule.send_at' => 'Use a UTC send time.']);
        }
        $after = $records->instant($data['audience']['purchased_after'] ?? null);
        $before = $records->instant($data['audience']['purchased_before'] ?? null);
        if ($after !== null && $before !== null && $after >= $before) {
            throw ValidationException::withMessages(['campaign.audience.purchased_before' => 'The purchase window end must be after its start.']);
        }
        $mode = (string) $data['audience']['previous_campaign_mode'];
        $previousId = is_string($data['audience']['previous_campaign_id'] ?? null) ? (string) $data['audience']['previous_campaign_id'] : null;
        if (in_array($mode, ['included', 'excluded'], true) && $previousId === null) {
            throw ValidationException::withMessages(['campaign.audience.previous_campaign_id' => 'Choose a campaign to include or exclude.']);
        }
        $categoryIds = [];
        foreach (is_array($data['audience']['category_ids'] ?? null) ? $data['audience']['category_ids'] : [] as $categoryId) {
            if (! is_string($categoryId) || ! Str::isUuid($categoryId)) {
                throw ValidationException::withMessages(['campaign.audience.category_ids' => 'Choose categories that exist.']);
            }
            abort_unless(DB::table('categories')->where('id', $categoryId)->exists(), 422);
            $categoryIds[] = strtolower($categoryId);
        }
        if ($previousId !== null) {
            abort_unless(DB::table('marketing_campaigns')->where('id', $previousId)->exists(), 422);
        }

        return DB::transaction(function () use ($actor, $data, $records, $sendAt, $after, $before, $mode, $previousId, $categoryIds): array {
            $now = now();
            $existing = null;
            if (isset($data['id'])) {
                $existing = DB::table('marketing_campaigns')->where('id', $data['id'])->lockForUpdate()->first();
                abort_unless($existing !== null, 404);
                abort_unless((int) $existing->revision === (int) $data['version'], 409, 'This campaign changed. Reload before saving.');
                abort_if((int) $existing->revision >= 4294967295, 409);
            }
            $id = isset($data['id']) ? (string) $data['id'] : (string) Str::uuid();
            $revision = $existing === null ? 1 : ((int) $existing->revision) + 1;
            $versionId = (string) Str::uuid();
            $filters = [
                'customer_status' => (string) $data['audience']['customer_status'],
                'category_ids' => $categoryIds,
                'purchased_after' => $records->iso($after),
                'purchased_before' => $records->iso($before),
                'previous_campaign_id' => $previousId,
                'previous_campaign_mode' => $mode,
            ];
            $schedule = ['send_at' => $records->iso($sendAt)];
            $caps = [
                'max_recipients' => (int) $data['caps']['max_recipients'],
                'per_contact_24h' => (int) $data['caps']['per_contact_24h'],
                'per_contact_7d' => (int) $data['caps']['per_contact_7d'],
            ];
            if ($existing === null) {
                DB::table('marketing_campaigns')->insert([
                    'id' => $id,
                    'name' => $data['name'],
                    'status' => 'draft',
                    'revision' => $revision,
                    'draft_version_id' => $versionId,
                    'activated_version_id' => null,
                    'activated_at' => null,
                    'paused_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('marketing_campaigns')->where('id', $id)->update([
                    'name' => $data['name'],
                    'revision' => $revision,
                    'draft_version_id' => $versionId,
                    'updated_at' => $now,
                ]);
            }
            DB::table('campaign_versions')->insert([
                'id' => $versionId,
                'campaign_id' => $id,
                'version' => $revision,
                'subject' => $data['subject'],
                'body_text' => $data['body_text'],
                'purpose' => $data['purpose'],
                'audience_filters' => json_encode($filters, JSON_THROW_ON_ERROR),
                'schedule' => json_encode($schedule, JSON_THROW_ON_ERROR),
                'caps' => json_encode($caps, JSON_THROW_ON_ERROR),
                'offer_reference' => $data['offer_reference'] ?? null,
                'created_by' => $actor->id,
                'created_at' => $now,
            ]);
            $status = $existing === null ? 'draft' : (string) $existing->status;
            app(RecordEvent::class)->record('platform.audit.recorded', 'marketing:campaign:'.$id.':'.$revision, [
                'action' => 'marketing.campaign.saved', 'subject_type' => 'marketing_campaign', 'subject_id' => $id,
                'actor_type' => 'staff', 'actor_id' => $actor->id, 'metadata' => ['version' => $revision],
            ]);

            return ['id' => $id, 'revision' => $revision, 'status' => $status];
        });
    }

    private function plainText(string $value, string $field): void
    {
        if (preg_match('/[<>\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', $value) === 1) {
            throw ValidationException::withMessages([$field => 'Campaign copy must be plain text.']);
        }
    }
}
