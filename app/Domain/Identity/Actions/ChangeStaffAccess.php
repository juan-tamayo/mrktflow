<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use LogicException;

final class ChangeStaffAccess
{
    /** @param array<string, mixed> $input */
    public function change(StaffAccount $actor, string $staffId, array $input): StaffAccount
    {
        return DB::transaction(function () use ($actor, $staffId, $input): StaffAccount {
            // Every staff privilege mutation takes this lock before reading accounts.
            if (DB::table('staff_security')->where('id', 1)->lockForUpdate()->first() === null) {
                throw new LogicException('Staff security lock is unavailable.');
            }
            $currentActor = StaffAccount::query()->whereKey($actor->id)->lockForUpdate()->first();
            if ($currentActor === null || ! $currentActor->hasPermission('staff.manage')) {
                throw new AuthorizationException;
            }
            $data = Validator::make(['access' => $input], [
                'access' => ['required', 'array:role,is_active,version'],
                'access.role' => ['required', Rule::in(['owner', 'manager', 'content_editor', 'fulfillment'])],
                'access.is_active' => ['required', 'boolean'],
                'access.version' => ['required', 'integer', 'min:1', 'max:4294967295'],
            ])->validate()['access'];
            $staff = StaffAccount::query()->whereKey($staffId)->lockForUpdate()->firstOrFail();
            abort_if($staff->session_version !== (int) $data['version'], 409, 'This account changed. Reload the staff list before saving.');
            $active = (bool) $data['is_active'];
            if ($staff->role === 'owner' && $staff->is_active && ($data['role'] !== 'owner' || ! $active)) {
                $owners = StaffAccount::query()->where('role', 'owner')->where('is_active', true)->lockForUpdate()->get();
                abort_if($owners->count() < 2, 409, 'Create another active owner before removing the final owner.');
            }
            $permissions = StaffPermissions::forRole($data['role']);
            if ($staff->role === $data['role'] && $staff->is_active === $active && $staff->permissions === $permissions) {
                return $staff;
            }
            if ($staff->session_version >= 4294967295) {
                throw new LogicException('Staff session version is exhausted.');
            }
            $staff->forceFill([
                'role' => $data['role'], 'permissions' => $permissions, 'is_active' => $active,
                'session_version' => $staff->session_version + 1, 'remember_token' => null,
            ])->save();
            app(RecordEvent::class)->record('platform.audit.recorded', 'staff:access:'.$staff->id.':'.$staff->session_version, [
                'action' => 'staff.access_changed', 'subject_type' => 'staff', 'subject_id' => $staff->id,
                'actor_type' => 'staff', 'actor_id' => $currentActor->id,
                'metadata' => ['version' => $staff->session_version],
            ]);

            return $staff;
        });
    }
}
