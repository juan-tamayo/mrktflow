<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Marketing\Support\CampaignAccess;
use App\Domain\Marketing\Support\CampaignRecords;
use Illuminate\Auth\Access\AuthorizationException;

final class PreviewSchedule
{
    /**
     * @return array{
     *     status: string,
     *     paused: bool,
     *     due: bool,
     *     dispatched: bool,
     *     next_send_at: ?string,
     *     would_send: int,
     *     capped: int,
     *     caps: array<string, int>,
     *     reason: ?string
     * }
     */
    public function preview(StaffAccount $actor, string $campaignId): array
    {
        if (! CampaignAccess::canView($actor)) {
            throw new AuthorizationException;
        }

        return $this->state($campaignId, false);
    }

    /**
     * @return array{
     *     status: string,
     *     paused: bool,
     *     due: bool,
     *     dispatched: bool,
     *     next_send_at: ?string,
     *     would_send: int,
     *     capped: int,
     *     caps: array<string, int>,
     *     reason: string
     * }
     */
    public function dispatch(StaffAccount $actor, string $campaignId): array
    {
        if (! $actor->hasPermission('marketing.send')) {
            throw new AuthorizationException;
        }
        $state = $this->state($campaignId, true);
        $state['reason'] = $state['reason'] ?? 'batches_not_enabled';

        return $state;
    }

    /**
     * @return array{
     *     status: string,
     *     paused: bool,
     *     due: bool,
     *     dispatched: bool,
     *     next_send_at: ?string,
     *     would_send: int,
     *     capped: int,
     *     caps: array<string, int>,
     *     reason: ?string
     * }
     */
    private function state(string $campaignId, bool $attempt): array
    {
        $records = app(CampaignRecords::class);
        $campaign = $records->campaign($campaignId);
        $version = $records->scheduledVersion($campaign);
        $audience = app(PreviewAudience::class)->evaluate($version);
        $sendAt = $records->instant(is_array($version['schedule'] ?? null) ? ($version['schedule']['send_at'] ?? null) : null);
        $paused = $records->string($campaign, 'status') === 'paused';
        $scheduled = $records->string($campaign, 'status') === 'scheduled';
        $due = $scheduled && ! $paused && $sendAt !== null && $sendAt->getTimestamp() <= now()->getTimestamp();
        $reason = null;
        if ($attempt) {
            if ($paused) {
                $reason = 'paused';
            } elseif (! $scheduled) {
                $reason = 'not_activated';
            } elseif (! $due) {
                $reason = 'not_due';
            } else {
                $reason = 'batches_not_enabled';
            }
        }

        return [
            'status' => $records->string($campaign, 'status'),
            'paused' => $paused,
            'due' => $due,
            'dispatched' => false,
            'next_send_at' => $paused ? null : $records->iso($sendAt),
            'would_send' => $audience['would_send'],
            'capped' => $audience['capped'],
            'caps' => [
                'max_recipients' => $records->int(is_array($version['caps'] ?? null) ? $version['caps'] : [], 'max_recipients'),
                'per_contact_24h' => $records->int(is_array($version['caps'] ?? null) ? $version['caps'] : [], 'per_contact_24h'),
                'per_contact_7d' => $records->int(is_array($version['caps'] ?? null) ? $version['caps'] : [], 'per_contact_7d'),
            ],
            'reason' => $reason,
        ];
    }
}
