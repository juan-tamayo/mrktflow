<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Actions;

use App\Domain\AiAuthoring\Data\AiAuthoringResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Advances a run's persisted repair index so at most two repairs share the
 * original budget reservation (AI-04). Job replay cannot reset the count.
 */
final class RepairCandidate
{
    public function canRepair(int $repairAttempt, AiAuthoringResult $result): bool
    {
        return $result->status === 'invalid_output' && $repairAttempt >= 0 && $repairAttempt < 2;
    }

    public function advance(string $runId): int
    {
        if (! Str::isUuid($runId) || $runId !== strtolower($runId)) {
            throw new InvalidArgumentException('Invalid authoring run.');
        }

        return DB::transaction(function () use ($runId): int {
            $run = DB::table('ai_runs')->where('id', $runId)->lockForUpdate()->first();
            if ($run === null) {
                throw new InvalidArgumentException('Invalid authoring run.');
            }
            $attempt = (int) $this->string($run, 'repair_attempt');
            if ($attempt >= 2) {
                throw new InvalidArgumentException('Repair attempts are exhausted.');
            }
            $next = $attempt + 1;
            DB::table('ai_runs')->where('id', $runId)->update([
                'repair_attempt' => $next,
                'provider_attempts' => (int) $this->string($run, 'provider_attempts') + 1,
                'updated_at' => now(),
            ]);

            return $next;
        });
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
