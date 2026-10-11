<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\CustomerMailConfiguration;
use App\Mail\CustomerChallengeMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

final class DeliverCustomerChallengeMail
{
    public function deliver(string $id): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Network mail delivery cannot run inside a database transaction.');
        }
        $message = $this->claim($id);
        if ($message === null) {
            return;
        }
        try {
            $accepted = Mail::mailer($message['mailer'])->to($message['email'])->send(new CustomerChallengeMessage(
                $id, $message['purpose'], $message['token'], $message['fromEmail'], $message['fromName'], $message['baseUrl'],
            ));
            if ($accepted === null) {
                throw new RuntimeException('Mail was not accepted.');
            }
        } catch (Throwable $exception) {
            $this->finish($id, $message['claimId'], false);
            Log::warning('identity.mail_transport_failed', ['delivery_id' => $id, 'error_code' => 'transport_failed', 'exception_class' => $exception::class]);

            return;
        }
        $this->finish($id, $message['claimId'], true);
    }

    /** @return array{claimId: string, mailer: string, email: string, token: string, purpose: string, fromEmail: string, fromName: string, baseUrl: string}|null */
    private function claim(string $id): ?array
    {
        return DB::transaction(function () use ($id): ?array {
            $initial = DB::table('customer_challenge_deliveries')->where('id', $id)->first();
            if ($initial === null) {
                return null;
            }
            $customer = CustomerAccount::query()->whereKey($initial->customer_id)->lockForUpdate()->first();
            $challenge = DB::table('customer_challenges')->where('id', $initial->challenge_id)->lockForUpdate()->first();
            $row = DB::table('customer_challenge_deliveries')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || ! in_array($row->status, ['pending', 'sending'], true) || now()->lt($row->available_at)
                || ($row->status === 'sending' && $row->lease_until !== null && now()->lt($row->lease_until))) {
                return null;
            }
            if ($customer === null || ! $customer->is_active || $challenge === null || $challenge->customer_id !== $customer->id
                || $challenge->token_digest === null || $challenge->consumed_at !== null || $challenge->revoked_at !== null
                || ! CarbonImmutable::parse($challenge->expires_at)->isFuture() || now()->gte($row->expires_at)
                || $challenge->session_version !== $customer->session_version || ! hash_equals($challenge->email_digest, hash('sha256', $customer->email))) {
                $this->update($id, ['status' => 'cancelled', 'payload' => null, 'last_error_code' => 'challenge_unavailable']);

                return null;
            }
            if ($row->attempts >= 6) {
                $this->update($id, ['status' => 'failed', 'last_error_code' => 'attempts_exhausted']);

                return null;
            }
            try {
                $payload = is_string($row->payload) ? json_decode(Crypt::decryptString($row->payload), true, flags: JSON_THROW_ON_ERROR) : null;
                $valid = is_array($payload) && ! Validator::make(['message' => $payload], [
                    'message' => ['required', 'array:email,token'],
                    'message.email' => ['required', 'string', 'email:rfc', 'max:254'],
                    'message.token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
                ])->fails();
                if (! $valid || $payload['email'] !== $customer->email || ! hash_equals($challenge->token_digest, hash('sha256', $payload['token']))) {
                    throw new RuntimeException('Invalid credential message.');
                }
            } catch (Throwable) {
                $this->update($id, ['status' => 'failed', 'last_error_code' => 'message_invalid']);

                return null;
            }
            $configuration = app(CustomerMailConfiguration::class)->ready();
            if ($configuration === null) {
                $this->update($id, ['status' => 'pending', 'available_at' => now()->addMinute(), 'last_error_code' => 'mail_configuration_unavailable']);

                return null;
            }
            $claim = (string) Str::uuid();
            DB::table('customer_challenge_deliveries')->where('id', $id)->update([
                'status' => 'sending', 'claim_id' => $claim, 'lease_until' => now()->addSeconds(120),
                'attempts' => $row->attempts + 1, 'last_error_code' => null, 'updated_at' => now(),
            ]);

            return $configuration + ['claimId' => $claim, 'email' => $payload['email'], 'token' => $payload['token'], 'purpose' => $challenge->purpose];
        });
    }

    private function finish(string $id, string $claim, bool $accepted): void
    {
        DB::transaction(function () use ($id, $claim, $accepted): void {
            $row = DB::table('customer_challenge_deliveries')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->status !== 'sending' || $row->claim_id !== $claim) {
                return;
            }
            if ($accepted) {
                $this->update($id, ['status' => 'delivered', 'payload' => null, 'delivered_at' => now(), 'last_error_code' => null]);
            } else {
                $delay = match ($row->attempts) {
                    1 => 10,
                    2 => 30,
                    3 => 120,
                    4 => 300,
                    default => 900,
                };
                $this->update($id, [
                    'status' => $row->attempts >= 6 ? 'failed' : 'pending',
                    'available_at' => now()->addSeconds($delay), 'last_error_code' => 'transport_failed',
                ]);
            }
        });
    }

    /** @param array<string, mixed> $changes */
    private function update(string $id, array $changes): void
    {
        DB::table('customer_challenge_deliveries')->where('id', $id)->update($changes + ['claim_id' => null, 'lease_until' => null, 'queued_until' => null, 'updated_at' => now()]);
    }
}
