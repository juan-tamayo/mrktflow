<?php

declare(strict_types=1);

namespace App\Domain\Platform\Consumers;

use App\Domain\Platform\Contracts\OutboxConsumer;
use App\Domain\Platform\Data\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class StoreAuditEvent implements OutboxConsumer
{
    public function handle(OutboxEvent $event): void
    {
        $data = Validator::make(['audit' => $event->payload], [
            'audit' => ['required', 'array:action,subject_type,subject_id,actor_type,actor_id,metadata'],
            'audit.action' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.-]{0,127}$/'],
            'audit.subject_type' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.-]{0,63}$/'],
            'audit.subject_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'audit.actor_type' => ['sometimes', Rule::in(['system', 'staff', 'customer'])],
            'audit.actor_id' => ['nullable', 'uuid'],
            'audit.metadata' => ['sometimes', 'array:version,reason_code'],
            'audit.metadata.version' => ['sometimes', 'integer', 'min:1'],
            'audit.metadata.reason_code' => ['sometimes', 'string', 'regex:/^[a-z][a-z0-9_.-]{0,63}$/'],
        ])->validate()['audit'];
        DB::table('audit_events')->insert([
            'id' => $event->id, 'action' => $data['action'], 'subject_type' => $data['subject_type'],
            'subject_id' => $data['subject_id'], 'actor_type' => $data['actor_type'] ?? 'system',
            'actor_id' => $data['actor_id'] ?? null, 'metadata' => json_encode($data['metadata'] ?? [], JSON_THROW_ON_ERROR),
            'correlation_id' => $event->correlationId, 'occurred_at' => $event->occurredAt,
        ]);
    }
}
