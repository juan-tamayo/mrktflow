<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\CustomerAccountAccess;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UpdateCustomerProfile
{
    /** @param array<string, mixed> $input */
    public function update(CustomerAccount $actor, array $input): CustomerAccount
    {
        $data = Validator::make(['profile' => $input], [
            'profile' => ['required', 'array:name,email,version,current_password'],
            'profile.name' => ['required', 'string', 'max:120'],
            'profile.email' => ['required', 'string', 'email:rfc', 'max:254'],
            'profile.version' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'profile.current_password' => ['nullable', 'string', 'max:72'],
        ])->validate()['profile'];
        $email = mb_strtolower(trim($data['email']), 'UTF-8');
        $changedEmail = $email !== $actor->email;
        $password = $data['current_password'] ?? null;
        if ($changedEmail && (! is_string($password) || strlen($password) > 72 || ! Hash::check($password, $actor->password))) {
            throw ValidationException::withMessages(['profile.current_password' => 'Enter your current password to change your email.']);
        }
        try {
            return DB::transaction(function () use ($actor, $data, $email, $changedEmail): CustomerAccount {
                $customer = app(CustomerAccountAccess::class)->lock($actor);
                abort_unless($customer->profile_version === (int) $data['version'], 409, 'Your profile changed. Reload before saving.');
                abort_if($customer->profile_version >= 4294967295 || ($changedEmail && $customer->session_version >= 4294967295), 409);
                if ($changedEmail && ! hash_equals($customer->password, $actor->password)) {
                    throw ValidationException::withMessages(['profile.current_password' => 'Enter your current password to change your email.']);
                }
                $customer->forceFill(['name' => $data['name'], 'email' => $email, 'profile_version' => $customer->profile_version + 1]);
                if ($changedEmail) {
                    $customer->forceFill(['email_verified_at' => null, 'remember_token' => null, 'session_version' => $customer->session_version + 1]);
                    DB::table('customer_challenges')->where('customer_id', $customer->id)->whereNull('consumed_at')->whereNull('revoked_at')
                        ->update(['revoked_at' => now(), 'token_digest' => null, 'updated_at' => now()]);
                }
                $customer->save();
                app(RecordEvent::class)->record('platform.audit.recorded', 'customer:profile:'.$customer->id.':'.$customer->profile_version, [
                    'action' => 'customer.profile_updated', 'subject_type' => 'customer', 'subject_id' => $customer->id,
                    'actor_type' => 'customer', 'actor_id' => $customer->id, 'metadata' => ['version' => $customer->profile_version],
                ]);

                return $customer;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! CustomerAccount::query()->where('email', $email)->whereKeyNot($actor->id)->exists()) {
                throw $exception;
            }
            throw ValidationException::withMessages(['profile.email' => 'This email could not be saved.']);
        }
    }
}
