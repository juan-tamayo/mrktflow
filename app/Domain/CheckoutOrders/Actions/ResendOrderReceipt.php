<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ResendOrderReceipt
{
    /**
     * @return array{id: string, intent_key: string}
     */
    public function resend(StaffAccount $actor, string $orderId, string $idempotencyKey): array
    {
        if (! $actor->hasPermission('orders.manage')) {
            throw new AuthorizationException;
        }
        if (! Str::isUuid($orderId) || $orderId !== strtolower($orderId)
            || ! Str::isUuid($idempotencyKey) || $idempotencyKey !== strtolower($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Send a stable receipt key.']);
        }

        return DB::transaction(function () use ($actor, $orderId, $idempotencyKey): array {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new NotFoundHttpException;
            }
            $intentKey = 'receipt:resend:'.$idempotencyKey;
            $existing = DB::table('order_receipt_intents')->where('intent_key', $intentKey)->lockForUpdate()->first();
            if ($existing !== null) {
                return ['id' => $this->string($existing, 'id'), 'intent_key' => $intentKey];
            }
            if ($this->string($order, 'financial_status') === 'unpaid') {
                throw ValidationException::withMessages(['order' => 'A receipt can be resent only for a paid order.']);
            }
            $id = (string) Str::uuid();
            try {
                DB::table('order_receipt_intents')->insert([
                    'id' => $id,
                    'order_id' => $orderId,
                    'source_event_id' => null,
                    'template' => 'order-receipt',
                    'recipient' => $this->string($order, 'email'),
                    'intent_key' => $intentKey,
                    'kind' => 'resend',
                    'status' => 'queued',
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $replay = DB::table('order_receipt_intents')->where('intent_key', $intentKey)->first();
                if ($replay === null) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Send a stable receipt key.']);
                }

                return ['id' => $this->string($replay, 'id'), 'intent_key' => $intentKey];
            }
            app(RecordOrderEvent::class)->record($orderId, 'receipt_resent', [
                'intent_id' => $id,
                'template' => 'order-receipt',
                'amount' => $this->int($order, 'grand_total_amount'),
                'currency' => $this->string($order, 'currency'),
                'exponent' => $this->int($order, 'exponent'),
                'number' => $this->string($order, 'number'),
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-receipt-resend:'.$id, [
                'action' => 'checkout.order.receipt_resent',
                'subject_type' => 'order',
                'subject_id' => $orderId,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['version' => 1],
            ]);

            return ['id' => $id, 'intent_key' => $intentKey];
        });
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
