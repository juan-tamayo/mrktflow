<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffCredentials;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Platform\Actions\RecordEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use LogicException;

final class AcceptStaffInvitation
{
    /** @param array<string, mixed> $input */
    public function accept(#[\SensitiveParameter] string $token, array $input): StaffAccount
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token) === 1, 404);

        return DB::transaction(function () use ($token, $input): StaffAccount {
            if (DB::table('staff_security')->where('id', 1)->lockForUpdate()->first() === null) {
                throw new LogicException('Staff security lock is unavailable.');
            }
            $invitation = DB::table('staff_invitations')->where('token_digest', hash('sha256', $token))->lockForUpdate()->first();
            abort_unless($invitation !== null && $invitation->accepted_at === null && $invitation->revoked_at === null
                && CarbonImmutable::parse($invitation->expires_at)->isFuture(), 404);
            $inviter = StaffAccount::query()->whereKey($invitation->inviter_id)->lockForUpdate()->first();
            abort_unless($inviter !== null && $inviter->hasPermission('staff.manage'), 404);
            abort_if(StaffAccount::query()->where('email', $invitation->email)->exists(), 409, 'A staff account already uses this email.');
            Validator::make(['owner' => $input], ['owner' => ['required', 'array:name,password,password_confirmation']])->validate();
            $data = StaffCredentials::validate($input + ['email' => $invitation->email]);
            $permissions = json_decode($invitation->permissions, true, flags: JSON_THROW_ON_ERROR);
            if ($permissions !== StaffPermissions::forRole($invitation->role)) {
                throw new LogicException('Invitation permissions do not match the server preset.');
            }
            $staff = new StaffAccount;
            $staff->forceFill([
                'name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']),
                'role' => $invitation->role, 'permissions' => $permissions, 'is_active' => true, 'session_version' => 1,
                // Possession of the invite secret proves access to the configured delivery channel only.
                // Email verification waits for the email delivery/verification workflow.
            ])->save();
            DB::table('staff_invitations')->where('id', $invitation->id)->update([
                'accepted_at' => now(), 'token_digest' => null, 'updated_at' => now(),
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'staff:accepted:'.$invitation->id, [
                'action' => 'staff.invitation_accepted', 'subject_type' => 'staff', 'subject_id' => $staff->id,
                'actor_type' => 'staff', 'actor_id' => $staff->id,
            ]);

            return $staff;
        });
    }
}
