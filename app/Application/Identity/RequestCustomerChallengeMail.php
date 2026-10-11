<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Actions\IssueCustomerChallenge;
use App\Domain\Identity\Actions\QueueCustomerChallengeMail;
use App\Domain\Identity\Data\CustomerChallenge;
use App\Domain\Identity\Models\CustomerAccount;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RequestCustomerChallengeMail
{
    public function verification(CustomerAccount $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $this->store(app(IssueCustomerChallenge::class)->verification($actor));
        });
    }

    public function passwordReset(string $email): void
    {
        DB::transaction(function () use ($email): void {
            $this->store(app(IssueCustomerChallenge::class)->passwordReset($email));
        });
    }

    private function store(?CustomerChallenge $challenge): void
    {
        if ($challenge === null) {
            return;
        }
        $row = DB::table('customer_challenges')->where('id', $challenge->id)->firstOrFail();
        $customer = CustomerAccount::query()->whereKey($row->customer_id)->firstOrFail();
        $id = (string) Str::uuid();
        DB::table('customer_challenge_deliveries')->insert([
            'id' => $id, 'challenge_id' => $challenge->id, 'customer_id' => $customer->id,
            'payload' => Crypt::encryptString(json_encode(['email' => $customer->email, 'token' => $challenge->token()], JSON_THROW_ON_ERROR)),
            'available_at' => now(), 'expires_at' => $challenge->expiresAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::afterCommit(fn () => app(QueueCustomerChallengeMail::class)->safelyPublish($id));
    }
}
