<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\CheckoutOrders\Actions\RecordOrderEvent;
use App\Domain\Fulfillment\Exceptions\FulfillmentIdempotencyConflict;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApproveReturn extends BaseReturnAction
{
    public const OPERATION = 'order-return-approve';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function approve(StaffAccount $actor, string $orderId, string $returnId, array $input, string $idempotencyKey): array
    {
        $this->authorize($actor);
        $this->assertId($orderId);
        $this->assertId($returnId);
        $this->assertKey($idempotencyKey);

        return DB::transaction(function () use ($actor, $orderId, $returnId, $input, $idempotencyKey): array {
            $this->lockOrder($orderId);
            $return = $this->lockReturn($orderId, $returnId);
            $posted = $this->quantities($input, true);
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
            if ($this->string($return, 'status') !== 'requested') {
                throw ValidationException::withMessages(['return' => 'This return is not waiting for approval.']);
            }
            $returnLines = $this->lockReturnLines($returnId);
            $approved = [];
            foreach ($posted as $item) {
                if (! isset($returnLines[$item['id']])) {
                    throw ValidationException::withMessages(['lines' => 'Approval lines must be lines on this return.']);
                }
                $approved[$item['id']] = $item['quantity'];
            }
            $any = false;
            foreach ($returnLines as $lineId => $row) {
                $quantity = $approved[$lineId] ?? 0;
                if ($quantity > $this->int($row, 'requested_qty')) {
                    throw ValidationException::withMessages(['lines' => 'Approved quantities cannot exceed the requested quantities.']);
                }
                if ($quantity > 0) {
                    $any = true;
                }
            }
            $now = now();
            $approvedLines = [];
            foreach ($returnLines as $lineId => $row) {
                $quantity = $approved[$lineId] ?? 0;
                DB::table('return_lines')->where('id', $this->string($row, 'id'))->update([
                    'approved_qty' => $quantity,
                ]);
                $approvedLines[] = ['id' => $lineId, 'quantity' => $quantity];
            }
            usort($approvedLines, fn (array $left, array $right): int => $left['id'] <=> $right['id']);
            $status = $any ? 'approved' : 'rejected';
            DB::table('order_returns')->where('id', $returnId)->update([
                'status' => $status,
                'updated_at' => $now,
            ]);
            app(RecordOrderEvent::class)->record($orderId, 'return_approved', [
                'return_id' => $returnId,
                'status' => $status,
                'lines' => $approvedLines,
            ], $returnId);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-return-approve:'.$returnId, [
                'action' => 'fulfillment.return.approved',
                'subject_type' => 'order',
                'subject_id' => $orderId,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['version' => 1, 'status' => $status],
            ]);
            $this->finishClaim($claim['id'], $returnId);

            return $this->view($returnId);
        });
    }
}
