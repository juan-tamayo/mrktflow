<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SetupAccess
{
    public function issueSecret(): string
    {
        return DB::transaction(function (): string {
            $installation = DB::table('installations')->where('id', 1)->lockForUpdate()->first();
            abort_if($installation === null || $installation->completed_at !== null || $installation->status === 'complete', 409, 'Installation is closed.');
            $secret = bin2hex(random_bytes(32));
            DB::table('installations')->where('id', 1)->update([
                'secret_digest' => hash('sha256', $secret), 'generation' => (string) Str::uuid(),
                'secret_expires_at' => now()->addDay(), 'updated_at' => now(),
            ]);

            return $secret;
        });
    }

    /** @return array{generation: string, expires: int} */
    public function unlock(string $secret): array
    {
        $installation = DB::table('installations')->where('id', 1)->first();
        $generation = $installation->generation ?? null;
        abort_unless($installation !== null && $installation->completed_at === null
            && $installation->status !== 'complete' && is_string($generation)
            && is_string($installation->secret_digest) && strlen($secret) === 64
            && hash_equals($installation->secret_digest, hash('sha256', $secret))
            && $installation->secret_expires_at !== null
            && CarbonImmutable::parse($installation->secret_expires_at)->isFuture(), 403, 'Setup access is unavailable.');

        return [
            'generation' => $generation,
            'expires' => min(now()->addMinutes(15)->getTimestamp(), CarbonImmutable::parse($installation->secret_expires_at)->getTimestamp()),
        ];
    }

    /** @param array<string, mixed> $grant */
    public function authorize(array $grant): void
    {
        $installation = DB::table('installations')->where('id', 1)->first();
        abort_unless($installation !== null && $installation->completed_at === null
            && $installation->status !== 'complete'
            && is_string($installation->generation) && is_string($grant['generation'] ?? null)
            && hash_equals($installation->generation, $grant['generation'])
            && is_int($grant['expires'] ?? null) && $grant['expires'] > now()->timestamp
            && $installation->secret_expires_at !== null
            && CarbonImmutable::parse($installation->secret_expires_at)->isFuture(), 403, 'Setup access is unavailable.');
    }
}
