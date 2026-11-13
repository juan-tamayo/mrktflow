<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Marketing\Support\CampaignRecords;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ActivateCampaign
{
    /** @return array{id: string, revision: int, status: string} */
    public function activate(StaffAccount $actor, string $campaignId, int $expectedVersion): array
    {
        if (! $actor->hasPermission('marketing.activate')) {
            throw new AuthorizationException;
        }
        $records = app(CampaignRecords::class);

        return DB::transaction(function () use ($actor, $campaignId, $expectedVersion, $records): array {
            $row = DB::table('marketing_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            abort_unless((int) $row->revision === $expectedVersion, 409, 'This campaign changed. Reload before activating.');
            abort_if((int) $row->revision >= 4294967295, 409);
            $campaign = $records->row($row);
            $version = $records->workingVersion($campaign);
            $versionId = $records->string($version, 'id');
            $preview = app(PreviewAudience::class)->evaluate($version);
            if ($preview['eligible'] < 1) {
                throw ValidationException::withMessages(['campaign' => 'Activate requires at least one verified opt-in recipient.']);
            }
            $now = now();
            $revision = (int) $row->revision + 1;
            DB::table('audience_snapshots')->where('campaign_id', $campaignId)->where('campaign_version_id', $versionId)->delete();
            foreach ($preview['contact_ids'] as $contactId) {
                DB::table('audience_snapshots')->insert([
                    'id' => (string) Str::uuid(),
                    'campaign_id' => $campaignId,
                    'campaign_version_id' => $versionId,
                    'contact_id' => $contactId,
                    'eligible' => true,
                    'exclusion_reason' => null,
                    'created_at' => $now,
                ]);
            }
            DB::table('marketing_campaigns')->where('id', $campaignId)->update([
                'status' => 'scheduled',
                'revision' => $revision,
                'activated_version_id' => $version['id'],
                'activated_at' => $now,
                'paused_at' => null,
                'updated_at' => $now,
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'marketing:campaign-activated:'.$campaignId.':'.$revision, [
                'action' => 'marketing.campaign.activated', 'subject_type' => 'marketing_campaign', 'subject_id' => $campaignId,
                'actor_type' => 'staff', 'actor_id' => $actor->id,
                'metadata' => ['version' => $revision, 'eligible' => $preview['eligible']],
            ]);

            return ['id' => $campaignId, 'revision' => $revision, 'status' => 'scheduled'];
        });
    }
}
