<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Support\NotificationCatalog;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class QueueNotification
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, intent_key: string}
     */
    public function queue(array $input): array
    {
        $intentKey = $input['intent_key'] ?? null;
        $template = $input['template'] ?? null;
        $recipient = $input['recipient'] ?? null;
        $kind = $input['kind'] ?? 'event';
        $facts = $input['facts'] ?? null;
        if (! is_string($intentKey) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_:.-]{0,190}$/D', $intentKey) !== 1) {
            throw new InvalidArgumentException('A stable notification intent key is required.');
        }
        if (! is_string($template)) {
            throw new InvalidArgumentException('Unknown notification template.');
        }
        $catalog = NotificationCatalog::definition($template);
        if (! is_string($kind) || ! in_array($kind, ['event', 'test'], true)) {
            throw new InvalidArgumentException('Unknown notification kind.');
        }
        if (! is_string($recipient) || Validator::make(['email' => $recipient], ['email' => ['required', 'email:rfc', 'max:254']])->fails()) {
            throw ValidationException::withMessages(['recipient' => 'A valid recipient email is required.']);
        }
        $recipient = mb_strtolower(trim($recipient), 'UTF-8');
        if (! is_array($facts) || Validator::make($facts, [
            'heading' => ['required', 'string', 'max:120', 'not_regex:/[\r\n]/'],
            'body' => ['required', 'string', 'max:2000'],
            'order_number' => ['sometimes', 'nullable', 'string', 'max:24', 'not_regex:/[\r\n]/'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:32'],
        ])->fails()) {
            throw ValidationException::withMessages(['facts' => 'Notification facts are invalid.']);
        }
        $purpose = NotificationCatalog::purpose($template, is_string($facts['purpose'] ?? null) ? $facts['purpose'] : null);
        $id = (string) Str::uuid();
        $payload = Crypt::encryptString((string) json_encode([
            'email' => $recipient,
            'heading' => $facts['heading'],
            'body' => $facts['body'],
            'order_number' => is_string($facts['order_number'] ?? null) ? $facts['order_number'] : null,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($id, $intentKey, $template, $catalog, $purpose, $recipient, $kind, $payload): array {
            $existing = DB::table('notification_deliveries')->where('intent_key', $intentKey)->lockForUpdate()->first();
            if ($existing !== null) {
                return ['id' => (string) $existing->id, 'intent_key' => $intentKey];
            }
            try {
                DB::table('notification_deliveries')->insert([
                    'id' => $id,
                    'intent_key' => $intentKey,
                    'template' => $template,
                    'policy_class' => $catalog['class'],
                    'purpose' => $purpose,
                    'recipient' => $recipient,
                    'kind' => $kind,
                    'payload' => $payload,
                    'status' => 'pending',
                    'attempts' => 0,
                    'available_at' => now(),
                    'expires_at' => now()->addDays(7),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $replay = DB::table('notification_deliveries')->where('intent_key', $intentKey)->first();
                if ($replay === null) {
                    throw new InvalidArgumentException('A stable notification intent key is required.');
                }

                return ['id' => (string) $replay->id, 'intent_key' => $intentKey];
            }
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:notification-queued:'.$id, [
                'action' => 'operations.notification.queued',
                'subject_type' => 'notification_delivery',
                'subject_id' => $id,
                'actor_type' => 'system',
                'actor_id' => null,
                'metadata' => ['template' => $template, 'policy_class' => $catalog['class'], 'kind' => $kind, 'version' => 1],
            ]);
            $publishId = $id;
            DB::afterCommit(fn () => app(PublishNotification::class)->safelyPublish($publishId));

            return ['id' => $id, 'intent_key' => $intentKey];
        });
    }
}
