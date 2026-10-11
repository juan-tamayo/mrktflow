<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\CustomerChallenge;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class IssueCustomerChallenge
{
    public function verification(CustomerAccount $actor): ?CustomerChallenge
    {
        return DB::transaction(function () use ($actor): ?CustomerChallenge {
            $customer = CustomerAccount::query()->whereKey($actor->id)->lockForUpdate()->first();
            if ($customer === null || ! $customer->is_active || $customer->email !== $actor->email || $customer->session_version !== $actor->session_version) {
                throw new AuthorizationException;
            }

            return $customer->email_verified_at === null ? $this->issue($customer, 'verify_email') : null;
        });
    }

    public function passwordReset(string $email): ?CustomerChallenge
    {
        Validator::make(['email' => $email], ['email' => ['required', 'string', 'email:rfc', 'max:254']])->validate();
        $email = mb_strtolower(trim($email), 'UTF-8');

        return DB::transaction(function () use ($email): ?CustomerChallenge {
            $customer = CustomerAccount::query()->where('email', $email)->lockForUpdate()->first();

            return $customer !== null && $customer->is_active ? $this->issue($customer, 'reset_password') : null;
        });
    }

    private function issue(CustomerAccount $customer, string $purpose): CustomerChallenge
    {
        DB::table('customer_challenges')->where('customer_id', $customer->id)->where('purpose', $purpose)->whereNull('consumed_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'token_digest' => null, 'updated_at' => now()]);
        $id = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        $expiresAt = $purpose === 'verify_email' ? CarbonImmutable::now()->addDay() : CarbonImmutable::now()->addHour();
        DB::table('customer_challenges')->insert([
            'id' => $id, 'customer_id' => $customer->id, 'purpose' => $purpose, 'token_digest' => hash('sha256', $token),
            'email_digest' => hash('sha256', $customer->email), 'session_version' => $customer->session_version, 'expires_at' => $expiresAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(RecordEvent::class)->record('platform.audit.recorded', 'customer:challenge:'.$id, [
            'action' => 'customer.'.$purpose.'_requested', 'subject_type' => 'customer_challenge', 'subject_id' => $id,
            'actor_type' => $purpose === 'verify_email' ? 'customer' : 'system',
            'actor_id' => $purpose === 'verify_email' ? $customer->id : null,
        ]);

        return new CustomerChallenge($id, $purpose, $token, $expiresAt);
    }
}
