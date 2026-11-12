<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Operations\Support\RequireOwner;
use Illuminate\Support\Facades\DB;

final class ListAuditLog
{
    public function __construct(private readonly RequireOwner $owners) {}

    /**
     * @return list<array{id: string, action: string, subject_type: string, subject_id: string, actor_type: string, actor_id: string|null, metadata: array<string, mixed>, correlation_id: string, occurred_at: string}>
     */
    public function forOwner(StaffAccount $actor): array
    {
        $this->owners->assert($actor);
        $events = [];
        $rows = DB::table('audit_events')->orderByDesc('occurred_at')->orderByDesc('id')->limit(50)->get();
        foreach ($rows as $row) {
            $raw = is_string($row->metadata ?? null) ? json_decode($row->metadata, true) : [];
            $metadata = [];
            if (is_array($raw)) {
                foreach ($raw as $key => $value) {
                    if ($key === 'version' && is_int($value)) {
                        $metadata['version'] = $value;
                    }
                    if ($key === 'reason_code' && is_string($value)) {
                        $metadata['reason_code'] = $value;
                    }
                }
            }
            $events[] = [
                'id' => (string) $row->id,
                'action' => (string) $row->action,
                'subject_type' => (string) $row->subject_type,
                'subject_id' => (string) $row->subject_id,
                'actor_type' => (string) $row->actor_type,
                'actor_id' => is_string($row->actor_id) ? $row->actor_id : null,
                'metadata' => $metadata,
                'correlation_id' => (string) $row->correlation_id,
                'occurred_at' => (string) $row->occurred_at,
            ];
        }

        return $events;
    }
}
