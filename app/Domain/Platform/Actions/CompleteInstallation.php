<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use Closure;
use Illuminate\Support\Facades\DB;

final class CompleteInstallation
{
    /**
     * @template T
     *
     * @param  array<string, mixed>  $grant
     * @param  Closure(): T  $persistOwner  Local database effects only, inside the installation lock.
     * @return T
     */
    public function complete(array $grant, Closure $persistOwner): mixed
    {
        return DB::transaction(function () use ($grant, $persistOwner): mixed {
            $installation = DB::table('installations')->where('id', 1)->lockForUpdate()->first();
            app(SetupAccess::class)->authorize($grant);
            app(InstallationPrerequisites::class)->assertReady();
            abort_unless($installation !== null && $installation->status === 'pending_owner'
                && DB::table('store_settings')->where('id', 1)->exists(), 409, 'Save store settings before creating the owner.');
            $owner = $persistOwner();
            DB::table('installations')->where('id', 1)->update([
                'status' => 'complete', 'completed_at' => now(), 'secret_digest' => null,
                'secret_expires_at' => null, 'generation' => null, 'updated_at' => now(),
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'installation:completed:1', [
                'action' => 'installation.completed', 'subject_type' => 'installation', 'subject_id' => '1',
            ]);

            return $owner;
        });
    }
}
