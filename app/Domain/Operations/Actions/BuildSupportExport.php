<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Operations\Support\RequireOwner;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Str;
use JsonException;

final class BuildSupportExport
{
    public function __construct(
        private readonly InspectHealth $health,
        private readonly ListAuditLog $audit,
        private readonly RequireOwner $owners,
    ) {}

    /**
     * @return array{filename: string, json: string, headers: array<string, string>}
     */
    public function forOwner(StaffAccount $actor): array
    {
        $this->owners->assert($actor);
        $snapshot = $this->health->forOwner($actor);
        $exportId = (string) Str::uuid();
        try {
            $json = json_encode([
                'version' => $snapshot['version'],
                'generated_at' => $snapshot['generated_at'],
                'thresholds' => $snapshot['thresholds'],
                'health' => [
                    'scheduler' => $snapshot['scheduler'],
                    'worker' => $snapshot['worker'],
                    'payments' => $snapshot['payments'],
                    'jobs' => $snapshot['jobs'],
                    'email' => $snapshot['email'],
                    'disk' => $snapshot['disk'],
                    'storage' => $snapshot['storage'],
                    'backup' => $snapshot['backup'],
                    'warnings' => $snapshot['warnings'],
                ],
                'failed_jobs' => $snapshot['jobs']['errors'],
                'audit' => $this->audit->forOwner($actor),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            $json = '{"version":"1.0.0","failed_jobs":[],"audit":[]}';
        }
        app(RecordEvent::class)->record('platform.audit.recorded', 'audit:support-export:'.$exportId, [
            'action' => 'operations.support.exported',
            'subject_type' => 'support_export',
            'subject_id' => $exportId,
            'actor_type' => 'staff',
            'actor_id' => $actor->id,
            'metadata' => ['version' => 1, 'reason_code' => 'diagnostics'],
        ]);

        return [
            'filename' => 'support-diagnostics.json',
            'json' => $json,
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        ];
    }
}
