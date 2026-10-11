<?php

declare(strict_types=1);

use App\Domain\Platform\Contracts\OutboxConsumer;
use App\Domain\Platform\Data\OutboxEvent;
use Illuminate\Support\Facades\DB;

final class CrashableOutboxConsumer implements OutboxConsumer
{
    public function handle(OutboxEvent $event): void
    {
        DB::table('audit_events')->insert([
            'id' => $event->id, 'action' => 'fixture.worker', 'subject_type' => 'fixture', 'subject_id' => '1',
            'metadata' => '{}', 'correlation_id' => $event->correlationId, 'occurred_at' => $event->occurredAt,
        ]);
        if (file_exists(base_path('.runtime/outbox-worker-pause-request'))) {
            file_put_contents(base_path('.runtime/outbox-worker-paused'), 'transaction-open');
            while (true) {
                usleep(20000);
            }
        }
    }
}
