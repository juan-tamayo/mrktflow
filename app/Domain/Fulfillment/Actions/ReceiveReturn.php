<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\CheckoutOrders\Actions\RecordOrderEvent;
use App\Domain\Fulfillment\Exceptions\FulfillmentIdempotencyConflict;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReceiveReturn extends BaseReturnAction
{
    public const OPERATION = 'order-return-receive';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function receive(StaffAccount $actor, string $orderId, string $returnId, array $input, string $idempotencyKey): array
    {
        $this->authorize($actor);
        $this->assertId($orderId);
        $this->assertId($returnId);
        $this->assertKey($idempotencyKey);

        return DB::transaction(function () use ($actor, $orderId, $returnId, $input, $idempotencyKey): array {
            $this->lockOrder($orderId);
            $return = $this->lockReturn($orderId, $returnId);
            $posted = $this->quantities($input, false);
            $hash = hash('sha256', json_encode([
                'order_id' => $orderId,
                'return_id' => $returnId,
                'lines' => $posted,
            ], JSON_THROW_ON_ERROR));
            $claim = $this->idempotency($actor, self::OPERATION, $idempotencyKey, $hash);
            if ($claim['replay']) {
                if ($claim['resource_id'] === null) {
                    throw new FulfillmentIdempotencyConflict;
                }

                return $this->view($claim['resource_id']);
            }
            if (! in_array($this->string($return, 'status'), ['approved', 'partially_received'], true)) {
                throw ValidationException::withMessages(['return' => 'Only an approved return can be received.']);
            }
            $returnLines = $this->lockReturnLines($returnId);
            foreach ($posted as $item) {
                $row = $returnLines[$item['id']] ?? null;
                if ($row === null) {
                    throw ValidationException::withMessages(['lines' => 'Receipt lines must be lines on this return.']);
                }
                $remaining = $this->int($row, 'approved_qty') - $this->int($row, 'received_qty');
                if ($item['quantity'] > $remaining) {
                    throw ValidationException::withMessages(['lines' => 'Received quantities cannot exceed the approved quantities still outstanding.']);
                }
            }
            $orderLines = $this->lockOrderLines($orderId);
            foreach ($posted as $item) {
                $row = $returnLines[$item['id']];
                DB::table('return_lines')->where('id', $this->string($row, 'id'))->update([
                    'received_qty' => $this->int($row, 'received_qty') + $item['quantity'],
                ]);
                $orderLine = $orderLines[$item['id']] ?? null;
                if ($orderLine !== null) {
                    DB::table('order_lines')->where('id', $item['id'])->update([
                        'eligibility_revision' => $this->int($orderLine, 'eligibility_revision') + 1,
                    ]);
                }
            }
            $complete = true;
            foreach ($this->lockReturnLines($returnId) as $row) {
                if ($this->int($row, 'received_qty') < $this->int($row, 'approved_qty')) {
                    $complete = false;
                }
            }
            DB::table('order_returns')->where('id', $returnId)->update([
                'status' => $complete ? 'received' : 'partially_received',
                'updated_at' => now(),
            ]);
            app(RecordOrderEvent::class)->record($orderId, 'return_received', [
                'return_id' => $returnId,
                'lines' => $posted,
            ], $claim['id']);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-return-receive:'.$claim['id'], [
                'action' => 'fulfillment.return.received',
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
