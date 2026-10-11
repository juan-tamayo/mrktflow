<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\CustomerChallengeStore;
use App\Domain\Platform\Actions\RecordEvent;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

final class ResetCustomerPassword
{
    /** @param array<string, mixed> $input */
    public function reset(#[\SensitiveParameter] string $token, array $input): CustomerAccount
    {
        $data = Validator::make(['password_reset' => $input], [
            'password_reset' => ['required', 'array:password,password_confirmation'],
            'password_reset.password' => ['required', 'string', Password::min(12), 'confirmed', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('Use a password of at most 72 bytes.');
                }
            }],
        ])->validate()['password_reset'];
        $password = Hash::make($data['password']);

        return DB::transaction(function () use ($token, $password): CustomerAccount {
            $store = app(CustomerChallengeStore::class);
            $locked = $store->lock($token, 'reset_password');
            $customer = $locked['customer'];
            abort_if($customer->session_version >= 4294967295, 409);
            $customer->forceFill(['password' => $password, 'session_version' => $customer->session_version + 1, 'remember_token' => null])->save();
            $store->consume($customer, $locked['challengeId']);
            app(RecordEvent::class)->record('platform.audit.recorded', 'customer:password_reset:'.$locked['challengeId'], [
                'action' => 'customer.password_reset', 'subject_type' => 'customer', 'subject_id' => $customer->id,
                'actor_type' => 'customer', 'actor_id' => $customer->id, 'metadata' => ['version' => $customer->session_version],
            ]);

            return $customer;
        });
    }
}
