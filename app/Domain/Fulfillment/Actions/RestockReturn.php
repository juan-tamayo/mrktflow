<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\Catalog\Contracts\InventoryAllocator;
use App\Domain\Catalog\Data\InventoryLine;
use App\Domain\Catalog\Data\InventoryMutation;
use App\Domain\CheckoutOrders\Actions\RecordOrderEvent;
use App\Domain\Fulfillment\Exceptions\FulfillmentIdempotencyConflict;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class RestockReturn extends BaseReturnAction
{
    public const OPERATION = 'order-return-restock';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function restock(StaffAccount $actor, string $orderId, string $returnId, array $input, string $idempotencyKey): array
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
            if (! in_array($this->string($return, 'status'), ['approved', 'partially_received', 'received'], true)) {
                throw ValidationException::withMessages(['return' => 'Only a return with received goods can be restocked.']);
            }
            $returnLines = $this->lockReturnLines($returnId);
            foreach ($posted as $item) {
                $row = $returnLines[$item['id']] ?? null;
                if ($row === null) {
                    throw ValidationException::withMessages(['lines' => 'Restock lines must be lines on this return.']);
                }
                $remaining = $this->int($row, 'received_qty') - $this->int($row, 'restocked_qty');
                if ($item['quantity'] > $remaining) {
                    throw ValidationException::withMessages(['lines' => 'Restock quantities cannot exceed the received quantities not yet restocked.']);
                }
            }
            $orderLines = $this->lockOrderLines($orderId);
            foreach ($posted as $item) {
                $row = $returnLines[$item['id']];
                DB::table('return_lines')->where('id', $this->string($row, 'id'))->update([
                    'restocked_qty' => $this->int($row, 'restocked_qty') + $item['quantity'],
                ]);
            }
            $this->restockStock($orderId, $idempotencyKey, $posted, $orderLines);
            app(RecordOrderEvent::class)->record($orderId, 'return_restocked', [
                'return_id' => $returnId,
                'lines' => $posted,
            ], $claim['id']);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-return-restock:'.$claim['id'], [
                'action' => 'fulfillment.return.restocked',
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

    /**
     * @param  list<array{id: string, quantity: int}>  $posted
     * @param  array<string, object>  $orderLines
     */
    private function restockStock(string $orderId, string $idempotencyKey, array $posted, array $orderLines): void
    {
        $quantities = [];
        foreach ($posted as $item) {
            $orderLine = $orderLines[$item['id']] ?? null;
            if ($orderLine === null) {
                continue;
            }
            $variantId = $this->string($orderLine, 'variant_id');
            $variant = DB::table('product_variants')->where('id', $variantId)->first();
            if ($variant === null || ! $this->bool($variant, 'tracks_inventory')) {
                continue;
            }
            $quantities[$variantId] = ($quantities[$variantId] ?? 0) + $item['quantity'];
        }
        if ($quantities === []) {
            return;
        }
        $lines = [];
        foreach ($quantities as $variantId => $quantity) {
            $lines[] = new InventoryLine($variantId, $quantity);
        }
        usort($lines, fn (InventoryLine $left, InventoryLine $right): int => $left->variantId <=> $right->variantId);
        app(InventoryAllocator::class)->restock(new InventoryMutation(
            $orderId,
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'mrktflow:return-restock:'.$idempotencyKey)->toString(),
            $lines,
        ));
    }

    private function bool(object $row, string $key): bool
    {
        $value = get_object_vars($row)[$key] ?? false;

        return $value === true || $value === 1 || $value === '1';
    }
}
