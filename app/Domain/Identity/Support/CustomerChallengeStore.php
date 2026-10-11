<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\CustomerAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CustomerChallengeStore
{
    /** @return array{customer: CustomerAccount, challengeId: string} */
    public function lock(#[\SensitiveParameter] string $token, string $purpose): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Customer challenge consumption requires a transaction.');
        }
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token) === 1, 404);
        $digest = hash('sha256', $token);
        $customerId = DB::table('customer_challenges')->where('token_digest', $digest)->where('purpose', $purpose)->value('customer_id');
        abort_unless(is_string($customerId), 404);
        // All issuers/consumers lock the customer first, then challenge rows.
        $customer = CustomerAccount::query()->whereKey($customerId)->lockForUpdate()->first();
        $challenge = DB::table('customer_challenges')->where('token_digest', $digest)->where('purpose', $purpose)->where('customer_id', $customerId)->lockForUpdate()->first();
        abort_unless($customer !== null && $customer->is_active && $challenge !== null
            && $challenge->consumed_at === null && $challenge->revoked_at === null
            && CarbonImmutable::parse($challenge->expires_at)->isFuture()
            && $challenge->session_version === $customer->session_version
            && hash_equals($challenge->email_digest, hash('sha256', $customer->email)), 404);

        return ['customer' => $customer, 'challengeId' => $challenge->id];
    }

    public function consume(CustomerAccount $customer, string $challengeId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Customer challenge consumption requires a transaction.');
        }
        DB::table('customer_challenges')->where('id', $challengeId)->where('customer_id', $customer->id)
            ->update(['consumed_at' => now(), 'token_digest' => null, 'updated_at' => now()]);
        DB::table('customer_challenges')->where('customer_id', $customer->id)->whereNull('consumed_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'token_digest' => null, 'updated_at' => now()]);
    }
}
