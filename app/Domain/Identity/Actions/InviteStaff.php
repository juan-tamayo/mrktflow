<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\StaffInvitation;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Platform\Actions\RecordEvent;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;

final class InviteStaff
{
    /** @param array<string, mixed> $input */
    public function invite(StaffAccount $actor, array $input): StaffInvitation
    {
        return DB::transaction(function () use ($actor, $input): StaffInvitation {
            if (DB::table('staff_security')->where('id', 1)->lockForUpdate()->first() === null) {
                throw new LogicException('Staff security lock is unavailable.');
            }
            $currentActor = StaffAccount::query()->whereKey($actor->id)->lockForUpdate()->first();
            if ($currentActor === null || ! $currentActor->hasPermission('staff.manage')) {
                throw new AuthorizationException;
            }
            $data = Validator::make(['invitation' => $input], [
                'invitation' => ['required', 'array:email,role'],
                'invitation.email' => ['required', 'string', 'email:rfc', 'max:254'],
                'invitation.role' => ['required', Rule::in(['owner', 'manager', 'content_editor', 'fulfillment'])],
            ])->validate()['invitation'];
            $email = mb_strtolower(trim($data['email']), 'UTF-8');
            abort_if(StaffAccount::query()->where('email', $email)->exists(), 409, 'A staff account already uses this email.');
            DB::table('staff_invitations')->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'token_digest' => null, 'updated_at' => now()]);
            $id = (string) Str::uuid();
            $token = bin2hex(random_bytes(32));
            $expiresAt = CarbonImmutable::now()->addHours(48);
            DB::table('staff_invitations')->insert([
                'id' => $id, 'inviter_id' => $currentActor->id, 'email' => $email,
                'role' => $data['role'], 'permissions' => json_encode(StaffPermissions::forRole($data['role']), JSON_THROW_ON_ERROR),
                'token_digest' => hash('sha256', $token), 'expires_at' => $expiresAt,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'staff:invitation:'.$id, [
                'action' => 'staff.invited', 'subject_type' => 'staff_invitation', 'subject_id' => $id,
                'actor_type' => 'staff', 'actor_id' => $currentActor->id,
            ]);

            return new StaffInvitation($id, $token, $expiresAt);
        });
    }
}
