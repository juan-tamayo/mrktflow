<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\CustomerCredentials;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class RegisterCustomer
{
    /** @param array<string, mixed> $input */
    public function register(array $input): CustomerAccount
    {
        $data = CustomerCredentials::validate($input);
        // Hash before taking the installation lock; expensive work need not serialize registrations.
        $password = Hash::make($data['password']);

        try {
            return DB::transaction(function () use ($data, $password): CustomerAccount {
                $installation = DB::table('installations')->where('id', 1)->sharedLock()->first();
                abort_unless($installation !== null && $installation->status === 'complete', 503);
                $customer = new CustomerAccount;
                $customer->forceFill([
                    'name' => $data['name'], 'email' => $data['email'], 'password' => $password,
                    'is_active' => true, 'session_version' => 1, 'email_verified_at' => null,
                ])->save();
                app(RecordEvent::class)->record('platform.audit.recorded', 'customer:registered:'.$customer->id, [
                    'action' => 'customer.registered', 'subject_type' => 'customer', 'subject_id' => $customer->id,
                    'actor_type' => 'customer', 'actor_id' => $customer->id,
                ]);

                return $customer;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! CustomerAccount::query()->where('email', $data['email'])->exists()) {
                throw $exception;
            }
            throw ValidationException::withMessages(['customer.email' => 'An account could not be created with these details. Sign in or reset your password.']);
        }
    }
}
