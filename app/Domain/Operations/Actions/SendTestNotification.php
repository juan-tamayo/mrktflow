<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Operations\Support\NotificationCatalog;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SendTestNotification
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, intent_key: string}
     */
    public function send(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('settings.manage')) {
            throw new AuthorizationException;
        }
        $template = $input['template'] ?? null;
        $idempotency = $input['idempotency_key'] ?? null;
        $recipient = $input['recipient'] ?? $actor->email;
        if (! is_string($template) || ! isset(NotificationCatalog::TEMPLATES[$template])) {
            throw ValidationException::withMessages(['template' => 'Choose a known template.']);
        }
        if (! is_string($idempotency) || ! Str::isUuid($idempotency) || $idempotency !== strtolower($idempotency)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Send a stable test key.']);
        }
        if (! is_string($recipient) || mb_strtolower(trim($recipient), 'UTF-8') !== $actor->email) {
            throw ValidationException::withMessages(['recipient' => 'Test mail can only be sent to your staff address.']);
        }
        $definition = NotificationCatalog::definition($template);
        $preview = app(PreviewNotification::class)->facts($template, $definition['label']);
        $facts = [
            'heading' => $preview['heading'],
            'body' => 'Test message. '.$preview['body'],
        ];
        if ($definition['class'] === 'marketing') {
            $facts['purpose'] = $definition['purpose'] ?? 'newsletter';
        }
        $queued = app(QueueNotification::class)->queue([
            'intent_key' => 'test:'.$actor->id.':'.$template.':'.$idempotency,
            'template' => $template,
            'recipient' => $actor->email,
            'kind' => 'test',
            'facts' => $facts,
        ]);
        app(RecordEvent::class)->record('platform.audit.recorded', 'audit:notification-test:'.$queued['id'], [
            'action' => 'operations.notification.test_queued',
            'subject_type' => 'notification_delivery',
            'subject_id' => $queued['id'],
            'actor_type' => 'staff',
            'actor_id' => $actor->id,
            'metadata' => ['template' => $template, 'version' => 1],
        ]);

        return $queued;
    }
}
