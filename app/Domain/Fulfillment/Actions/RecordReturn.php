<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\CheckoutOrders\Actions\RecordOrderEvent;
use App\Domain\Fulfillment\Exceptions\FulfillmentIdempotencyConflict;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RecordReturn extends BaseReturnAction
{
    public const OPERATION = 'order-return';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function record(StaffAccount $actor, string $orderId, array $input, string $idempotencyKey): array
    {
        $this->authorize($actor);
        $this->assertId($orderId);
        $this->assertKey($idempotencyKey);

        return DB::transaction(function () use ($actor, $orderId, $input, $idempotencyKey): array {
            $order = $this->lockOrder($orderId);
            $reason = is_string($input['reason'] ?? null) ? trim((string) $input['reason']) : '';
            if ($reason === '' || mb_strlen($reason) > 500) {
                throw ValidationException::withMessages(['reason' => 'Enter a return reason of at most 500 characters.']);
            }
            $lines = $this->quantities($input, false);
            $hash = hash('sha256', json_encode([
                'order_id' => $orderId,
                'reason' => $reason,
                'lines' => $lines,
            ], JSON_THROW_ON_ERROR));
            $claim = $this->idempotency($actor, self::OPERATION, $idempotencyKey, $hash);
            if ($claim['replay']) {
                if ($claim['resource_id'] === null) {
                    throw new FulfillmentIdempotencyConflict;
                }

                return $this->view($claim['resource_id']);
            }
            if (! in_array($this->string($order, 'status'), ['confirmed', 'on_hold', 'completed'], true)) {
                throw ValidationException::withMessages(['order' => 'Returns need a paid order with shipped goods.']);
            }
            $byId = $this->lockOrderLines($orderId);
            $committed = $this->committedQuantities($orderId);
            foreach ($lines as $item) {
                $row = $byId[$item['id']] ?? null;
                if ($row === null || $this->string($row, 'type') !== 'physical') {
                    throw ValidationException::withMessages(['lines' => 'Return lines must be physical lines on this order.']);
                }
                $available = $this->int($row, 'shipped_qty') - ($committed[$item['id']] ?? 0);
                if ($item['quantity'] > $available) {
                    throw ValidationException::withMessages(['lines' => 'Return quantities exceed the shipped quantities still returnable.']);
                }
            }
            $now = now();
            $returnId = (string) Str::uuid();
            DB::table('order_returns')->insert([
                'id' => $returnId,
                'order_id' => $orderId,
                'staff_id' => $actor->id,
                'status' => 'requested',
                'reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($lines as $item) {
                DB::table('return_lines')->insert([
                    'id' => (string) Str::uuid(),
                    'return_id' => $returnId,
                    'order_line_id' => $item['id'],
                    'requested_qty' => $item['quantity'],
                    'approved_qty' => 0,
                    'received_qty' => 0,
                    'restocked_qty' => 0,
                ]);
            }
            app(RecordOrderEvent::class)->record($orderId, 'return_requested', [
                'return_id' => $returnId,
                'reason' => $reason,
                'lines' => $lines,
            ], $returnId);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-return:'.$returnId, [
                'action' => 'fulfillment.return.requested',
                'subject_type' => 'order',
                'subject_id' => $orderId,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['version' => 1],
            ]);
            $this->finishClaim($claim['id'], $returnId);

            return $this->view($returnId);
        });
    }
}
