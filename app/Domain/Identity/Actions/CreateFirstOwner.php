<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffCredentials;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

final class CreateFirstOwner
{
    /** @param array<string, mixed> $input */
    public function create(array $input): StaffAccount
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('First-owner creation requires the application setup transaction.');
        }
        if (DB::table('staff_security')->where('id', 1)->lockForUpdate()->first() === null) {
            throw new LogicException('Staff security lock is unavailable.');
        }
        abort_if(StaffAccount::query()->exists(), 409, 'Initial staff creation is already closed.');
        $data = StaffCredentials::validate($input);
        $owner = new StaffAccount;
        $owner->forceFill([
            'name' => $data['name'], 'email' => $data['email'],
            'password' => Hash::make($data['password']), 'role' => 'owner', 'permissions' => StaffPermissions::forRole('owner'),
            'is_active' => true, 'session_version' => 1,
        ])->save();
        app(RecordEvent::class)->record('platform.audit.recorded', 'staff:created:'.$owner->id, [
            'action' => 'staff.owner_created', 'subject_type' => 'staff', 'subject_id' => $owner->id,
            'actor_type' => 'staff', 'actor_id' => $owner->id,
        ]);

        return $owner;
    }
}
