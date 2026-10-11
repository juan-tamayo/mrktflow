<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Jobs\ConsumeOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use LogicException;

final class PublishOutbox
{
    public function forEvent(string $eventId): int
    {
        $ids = DB::table('consumer_receipts')->where('event_id', $eventId)->where('status', 'pending')->limit(20)->pluck('id');
        $published = 0;
        foreach ($ids as $id) {
            $published += $this->publish((int) $id);
        }

        return $published;
    }

    public function recover(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Recovery batch must contain between 1 and 1000 receipts.');
        }
        $ids = DB::table('consumer_receipts')->where('status', 'pending')->where('available_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('queued_until')->orWhere('queued_until', '<=', now()))
            ->orderBy('id')->limit($limit)->pluck('id');
        $published = 0;
        foreach ($ids as $id) {
            $published += $this->publish((int) $id);
        }

        return $published;
    }

    private function publish(int $receiptId): int
    {
        $connection = config()->array('queue.connections.database-outbox');
        if ($connection['driver'] !== 'database' || $connection['connection'] !== 'mysql'
            || $connection['after_commit'] !== false || config('database.default') !== 'mysql') {
            throw new LogicException('Outbox publication requires the transactional MySQL queue connection.');
        }

        return DB::transaction(function () use ($receiptId): int {
            $receipt = DB::table('consumer_receipts')->where('id', $receiptId)->lockForUpdate()->first();
            if ($receipt === null || $receipt->status !== 'pending' || now()->lt($receipt->available_at)
                || ($receipt->queued_until !== null && now()->lt($receipt->queued_until))) {
                return 0;
            }
            // Queue insertion and its lease share this connection/transaction. Never defer this insertion.
            Queue::connection('database-outbox')->push(new ConsumeOutbox($receipt->event_id, $receipt->consumer), '', 'outbox');
            DB::table('consumer_receipts')->where('id', $receiptId)->update([
                'queued_until' => now()->addSeconds(90), 'updated_at' => now(),
            ]);

            return 1;
        });
    }
}
