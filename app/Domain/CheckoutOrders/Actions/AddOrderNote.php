<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AddOrderNote
{
    /**
     * @return array{id: string, body: string}
     */
    public function add(StaffAccount $actor, string $orderId, string $body): array
    {
        if (! $actor->hasPermission('orders.read')) {
            throw new AuthorizationException;
        }
        $text = trim($body);
        if ($text === '' || mb_strlen($text) > 2000) {
            throw ValidationException::withMessages(['note' => 'Enter an internal note of at most 2000 characters.']);
        }
        if (! Str::isUuid($orderId) || $orderId !== strtolower($orderId)) {
            throw new NotFoundHttpException;
        }

        return DB::transaction(function () use ($actor, $orderId, $text): array {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new NotFoundHttpException;
            }
            $id = (string) Str::uuid();
            $now = now();
            DB::table('order_notes')->insert([
                'id' => $id,
                'order_id' => $orderId,
                'staff_id' => $actor->id,
                'body' => $text,
                'created_at' => $now,
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-note:'.$id, [
                'action' => 'checkout.order.note_added',
                'subject_type' => 'order',
                'subject_id' => $orderId,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['version' => 1],
            ]);

            return ['id' => $id, 'body' => $text];
        });
    }
}
