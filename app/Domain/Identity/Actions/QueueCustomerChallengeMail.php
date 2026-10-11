<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Jobs\SendCustomerChallengeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class QueueCustomerChallengeMail
{
    public function safelyPublish(string $id): void
    {
        try {
            $this->publish($id);
        } catch (Throwable $exception) {
            Log::warning('identity.mail_publication_failed', ['delivery_id' => $id, 'error_code' => 'publication_failed', 'exception_class' => $exception::class]);
        }
    }

    public function publish(string $id): int
    {
        $connection = config()->array('queue.connections.database-outbox');
        if ($connection['driver'] !== 'database' || $connection['connection'] !== 'mysql'
            || $connection['after_commit'] !== false || config('database.default') !== 'mysql') {
            throw new LogicException('Identity mail publication requires the transactional MySQL queue connection.');
        }

        return DB::transaction(function () use ($id): int {
            $row = DB::table('customer_challenge_deliveries')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || ! in_array($row->status, ['pending', 'sending'], true)
                || now()->lt($row->available_at) || now()->gte($row->expires_at)
                || ($row->queued_until !== null && now()->lt($row->queued_until))
                || ($row->status === 'sending' && $row->lease_until !== null && now()->lt($row->lease_until))) {
                return 0;
            }
            Queue::connection('database-outbox')->push(new SendCustomerChallengeMail($id), '', 'identity-mail');
            DB::table('customer_challenge_deliveries')->where('id', $id)->update(['queued_until' => now()->addSeconds(90), 'updated_at' => now()]);

            return 1;
        });
    }

    public function recover(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Recovery batches require between 1 and 1000 deliveries.');
        }
        $expired = DB::table('customer_challenge_deliveries')->whereNotNull('payload')->where('expires_at', '<=', now())
            ->where(fn ($query) => $query->where('status', '!=', 'sending')->orWhere('lease_until', '<=', now()))
            ->orderBy('expires_at')->limit($limit)->pluck('id');
        foreach ($expired as $id) {
            DB::transaction(function () use ($id): void {
                $row = DB::table('customer_challenge_deliveries')->where('id', $id)->lockForUpdate()->first();
                if ($row !== null && now()->gte($row->expires_at) && ($row->status !== 'sending' || $row->lease_until === null || now()->gte($row->lease_until))) {
                    DB::table('customer_challenge_deliveries')->where('id', $id)->update([
                        'status' => 'cancelled', 'payload' => null, 'claim_id' => null, 'lease_until' => null,
                        'queued_until' => null, 'last_error_code' => 'challenge_expired', 'updated_at' => now(),
                    ]);
                }
            });
        }
        $ids = DB::table('customer_challenge_deliveries')->whereIn('status', ['pending', 'sending'])->where('available_at', '<=', now())->where('expires_at', '>', now())
            ->where(fn ($query) => $query->whereNull('queued_until')->orWhere('queued_until', '<=', now()))
            ->where(fn ($query) => $query->where('status', 'pending')->orWhere('lease_until', '<=', now()))
            ->orderBy('created_at')->orderBy('id')->limit($limit)->pluck('id');
        $published = 0;
        foreach ($ids as $id) {
            $published += $this->publish($id);
        }

        return $published;
    }
}
