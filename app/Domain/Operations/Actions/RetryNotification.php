<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RetryNotification
{
    public function retry(StaffAccount $actor, string $id): bool
    {
        if (! $actor->hasPermission('settings.manage')) {
            throw new AuthorizationException;
        }
        if (! Str::isUuid($id) || $id !== strtolower($id)) {
            throw ValidationException::withMessages(['delivery' => 'Provide a delivery UUID.']);
        }
        $reset = DB::transaction(function () use ($id): bool {
            $row = DB::table('notification_deliveries')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || (string) $row->status !== 'failed' || $row->payload === null || now()->gte($row->expires_at)) {
                return false;
            }
            DB::table('notification_deliveries')->where('id', $id)->update([
                'status' => 'pending', 'attempts' => 0, 'claim_id' => null, 'lease_until' => null, 'queued_until' => null,
                'last_error_code' => null, 'available_at' => now(), 'updated_at' => now(),
            ]);

            return true;
        });
        if (! $reset) {
            throw ValidationException::withMessages(['delivery' => 'Only an unexpired failed delivery can be retried.']);
        }
        app(RecordEvent::class)->record('platform.audit.recorded', 'audit:notification-retry:'.$id, [
            'action' => 'operations.notification.retried',
            'subject_type' => 'notification_delivery',
            'subject_id' => $id,
            'actor_type' => 'staff',
            'actor_id' => $actor->id,
            'metadata' => ['version' => 1],
        ]);
        app(PublishNotification::class)->safelyPublish($id);

        return true;
    }
}
