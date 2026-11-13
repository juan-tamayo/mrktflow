<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PauseCampaign
{
    /** @return array{id: string, revision: int, status: string} */
    public function pause(StaffAccount $actor, string $campaignId, int $expectedVersion): array
    {
        return $this->setStatus($actor, $campaignId, $expectedVersion, 'paused');
    }

    /** @return array{id: string, revision: int, status: string} */
    public function resume(StaffAccount $actor, string $campaignId, int $expectedVersion): array
    {
        return $this->setStatus($actor, $campaignId, $expectedVersion, 'scheduled');
    }

    /** @return array{id: string, revision: int, status: string} */
    private function setStatus(StaffAccount $actor, string $campaignId, int $expectedVersion, string $status): array
    {
        if (! $actor->hasPermission('marketing.activate')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $campaignId, $expectedVersion, $status): array {
            $row = DB::table('marketing_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            abort_unless((int) $row->revision === $expectedVersion, 409, 'This campaign changed. Reload before updating the schedule.');
            abort_if((int) $row->revision >= 4294967295, 409);
            $current = (string) $row->status;
            if ($status === 'paused' && $current !== 'scheduled') {
                throw ValidationException::withMessages(['campaign' => 'Only a scheduled campaign can be paused.']);
            }
            if ($status === 'scheduled' && $current !== 'paused') {
                throw ValidationException::withMessages(['campaign' => 'Only a paused campaign can be resumed.']);
            }
            $now = now();
            $revision = (int) $row->revision + 1;
            DB::table('marketing_campaigns')->where('id', $campaignId)->update([
                'status' => $status,
                'revision' => $revision,
                'paused_at' => $status === 'paused' ? $now : null,
                'updated_at' => $now,
            ]);
            $action = $status === 'paused' ? 'marketing.campaign.paused' : 'marketing.campaign.resumed';
            app(RecordEvent::class)->record('platform.audit.recorded', 'marketing:campaign-'.$status.':'.$campaignId.':'.$revision, [
                'action' => $action, 'subject_type' => 'marketing_campaign', 'subject_id' => $campaignId,
                'actor_type' => 'staff', 'actor_id' => $actor->id, 'metadata' => ['version' => $revision],
            ]);

            return ['id' => $campaignId, 'revision' => $revision, 'status' => $status];
        });
    }
}
