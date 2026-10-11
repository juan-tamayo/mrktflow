<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\CustomerChallengeStore;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Facades\DB;

final class VerifyCustomerEmail
{
    public function verify(CustomerAccount $actor, #[\SensitiveParameter] string $token): CustomerAccount
    {
        return DB::transaction(function () use ($actor, $token): CustomerAccount {
            $store = app(CustomerChallengeStore::class);
            $locked = $store->lock($token, 'verify_email');
            $customer = $locked['customer'];
            abort_unless($actor->id === $customer->id && $actor->email === $customer->email
                && $actor->session_version === $customer->session_version && $customer->email_verified_at === null, 404);
            abort_if($customer->session_version >= 4294967295, 409);
            $customer->forceFill(['email_verified_at' => now(), 'session_version' => $customer->session_version + 1, 'remember_token' => null])->save();
            $store->consume($customer, $locked['challengeId']);
            app(RecordEvent::class)->record('platform.audit.recorded', 'customer:verified:'.$locked['challengeId'], [
                'action' => 'customer.email_verified', 'subject_type' => 'customer', 'subject_id' => $customer->id,
                'actor_type' => 'customer', 'actor_id' => $customer->id, 'metadata' => ['version' => $customer->session_version],
            ]);

            return $customer;
        });
    }
}
