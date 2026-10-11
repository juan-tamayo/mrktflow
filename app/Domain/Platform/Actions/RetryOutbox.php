<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use Illuminate\Support\Facades\DB;

final class RetryOutbox
{
    public function retry(string $eventId, string $consumerId): bool
    {
        return DB::transaction(function () use ($eventId, $consumerId): bool {
            $event = DB::table('outbox_events')->where('id', $eventId)->lockForUpdate()->first();
            $receipt = DB::table('consumer_receipts')->where('event_id', $eventId)
                ->where('consumer', $consumerId)->lockForUpdate()->first();
            if ($event === null || $receipt === null || $receipt->status !== 'failed') {
                return false;
            }
            DB::table('consumer_receipts')->where('id', $receipt->id)->update([
                'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'queued_until' => null,
                'last_error_code' => null, 'updated_at' => now(),
            ]);

            return true;
        });
    }
}
