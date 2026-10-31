<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Contracts\InventoryAllocator;
use App\Domain\Catalog\Data\InventoryAllocation;
use App\Domain\Catalog\Data\InventoryHold;
use App\Domain\Catalog\Data\InventoryLine;
use App\Domain\Catalog\Data\InventoryMutation;
use App\Domain\Catalog\Data\InventoryReceipt;
use App\Domain\Catalog\Exceptions\CatalogInsufficientStock;
use App\Domain\Catalog\Exceptions\CatalogInvalidAllocation;
use App\Domain\Catalog\Exceptions\CatalogInventoryConflict;
use App\Domain\Catalog\Exceptions\CatalogInventoryUntracked;
use App\Domain\ShoppingTools\Actions\EvaluateWatchEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AllocateInventory implements InventoryAllocator
{
    public function hold(InventoryHold $hold): InventoryReceipt
    {
        $lines = $this->sorted($hold->lines);

        return DB::transaction(function () use ($hold, $lines): InventoryReceipt {
            $this->lockBalances($this->variantIds($lines));
            if ($this->claim($hold->operationKey, $hold->intentId, 'hold', $this->hash('hold', $hold->intentId, $lines))) {
                return $this->receipt($hold->intentId);
            }
            $now = now();
            foreach ($lines as $line) {
                $balance = $this->lockedTrackedBalance($line->variantId);
                if ($this->intValue($balance, 'on_hand') - $this->intValue($balance, 'reserved') < $line->quantity) {
                    throw new CatalogInsufficientStock;
                }
            }
            foreach ($lines as $line) {
                $reservationId = (string) Str::uuid();
                DB::table('stock_reservations')->insert([
                    'id' => $reservationId,
                    'intent_id' => $hold->intentId,
                    'variant_id' => $line->variantId,
                    'original_qty' => $line->quantity,
                    'shipped_qty' => 0,
                    'released_qty' => 0,
                    'remaining_qty' => $line->quantity,
                    'status' => 'active',
                    'expires_at' => $hold->expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('inventory_balances')->where('variant_id', $line->variantId)->increment('reserved', $line->quantity);
                $this->move('hold:'.$hold->operationKey.':'.$line->variantId, $line->variantId, $reservationId, 0, $line->quantity, 'hold', $now);
            }

            return $this->receipt($hold->intentId);
        });
    }

    public function rehold(string $intentId, string $operationKey): InventoryReceipt
    {
        if (! Str::isUuid($intentId) || $intentId !== strtolower($intentId)
            || ! Str::isUuid($operationKey) || $operationKey !== strtolower($operationKey)) {
            throw new InvalidArgumentException('Invalid inventory rehold.');
        }

        return DB::transaction(function () use ($intentId, $operationKey): InventoryReceipt {
            $reservations = $this->lockIntent($intentId);
            if ($this->claim($operationKey, $intentId, 'hold', $this->hash('rehold', $intentId, []))) {
                return $this->receipt($intentId);
            }
            $released = [];
            foreach ($reservations as $reservation) {
                $status = $this->stringValue($reservation, 'status');
                if (in_array($status, ['active', 'committed'], true)) {
                    continue;
                }
                if ($status !== 'released' || $this->intValue($reservation, 'remaining_qty') !== 0
                    || $this->intValue($reservation, 'shipped_qty') !== 0) {
                    throw new CatalogInvalidAllocation;
                }
                $released[] = $reservation;
            }
            if ($released === []) {
                return $this->receipt($intentId);
            }
            foreach ($released as $reservation) {
                $quantity = $this->intValue($reservation, 'original_qty');
                $balance = $this->lockedTrackedBalance($this->stringValue($reservation, 'variant_id'));
                if ($this->intValue($balance, 'on_hand') - $this->intValue($balance, 'reserved') < $quantity) {
                    throw new CatalogInsufficientStock;
                }
            }
            $now = now();
            $expires = $now->copy()->utc()->addMinutes(30);
            foreach ($released as $reservation) {
                $quantity = $this->intValue($reservation, 'original_qty');
                $id = $this->stringValue($reservation, 'id');
                $variantId = $this->stringValue($reservation, 'variant_id');
                DB::table('stock_reservations')->where('id', $id)->update([
                    'released_qty' => 0,
                    'remaining_qty' => $quantity,
                    'status' => 'active',
                    'expires_at' => $expires,
                    'updated_at' => $now,
                ]);
                DB::table('inventory_balances')->where('variant_id', $variantId)->increment('reserved', $quantity);
                $this->move('hold:'.$operationKey.':'.$variantId, $variantId, $id, 0, $quantity, 'hold', $now);
            }

            return $this->receipt($intentId);
        });
    }

    public function commit(string $intentId, string $operationKey): InventoryReceipt
    {
        if (! Str::isUuid($intentId) || $intentId !== strtolower($intentId)
            || ! Str::isUuid($operationKey) || $operationKey !== strtolower($operationKey)) {
            throw new InvalidArgumentException('Invalid inventory commit.');
        }

        return DB::transaction(function () use ($intentId, $operationKey): InventoryReceipt {
            $reservations = $this->lockIntent($intentId);
            if ($this->claim($operationKey, $intentId, 'commit', $this->hash('commit', $intentId, []))) {
                return $this->receipt($intentId);
            }
            foreach ($reservations as $reservation) {
                if ($this->stringValue($reservation, 'status') !== 'active') {
                    throw new CatalogInvalidAllocation;
                }
                $id = $this->stringValue($reservation, 'id');
                $variantId = $this->stringValue($reservation, 'variant_id');
                DB::table('stock_reservations')->where('id', $id)->update([
                    'status' => 'committed',
                    'updated_at' => now(),
                ]);
                $this->move('commit:'.$operationKey.':'.$variantId, $variantId, $id, 0, 0, 'commit', now());
            }

            return $this->receipt($intentId);
        });
    }

    public function release(InventoryMutation $mutation): InventoryReceipt
    {
        return $this->mutate($mutation, 'release');
    }

    public function ship(InventoryMutation $mutation): InventoryReceipt
    {
        return $this->mutate($mutation, 'ship');
    }

    public function restock(InventoryMutation $mutation): InventoryReceipt
    {
        $lines = $this->sorted($mutation->lines);

        return DB::transaction(function () use ($mutation, $lines): InventoryReceipt {
            $this->lockBalances($this->variantIds($lines));
            if ($this->claim($mutation->operationKey, $mutation->intentId, 'restock', $this->hash('restock', $mutation->intentId, $lines))) {
                return $this->receipt($mutation->intentId);
            }
            $now = now();
            foreach ($lines as $line) {
                $balance = $this->lockedTrackedBalance($line->variantId);
                DB::table('inventory_balances')->where('variant_id', $line->variantId)->update([
                    'on_hand' => $this->intValue($balance, 'on_hand') + $line->quantity,
                    'updated_at' => $now,
                ]);
                $reservationId = DB::table('stock_reservations')
                    ->where('intent_id', $mutation->intentId)
                    ->where('variant_id', $line->variantId)
                    ->value('id');
                $this->move(
                    'restock:'.$mutation->operationKey.':'.$line->variantId,
                    $line->variantId,
                    is_string($reservationId) ? $reservationId : null,
                    $line->quantity,
                    0,
                    'restock',
                    $now,
                );
                // SHOP-04: this restock act is a merchant restock
                // source (unlike hold/commit/release above, which
                // never evaluate): fire only when the committed
                // available position enters availability.
                $availableBefore = $this->intValue($balance, 'on_hand') - $this->intValue($balance, 'reserved');
                if ($availableBefore <= 0 && $availableBefore + $line->quantity > 0) {
                    app(EvaluateWatchEvent::class)->stock($line->variantId, 'restock:'.$mutation->operationKey);
                }
            }

            return $this->receipt($mutation->intentId);
        });
    }

    private function mutate(InventoryMutation $mutation, string $action): InventoryReceipt
    {
        $lines = $this->sorted($mutation->lines);

        return DB::transaction(function () use ($mutation, $action, $lines): InventoryReceipt {
            $this->lockIntent($mutation->intentId);
            $this->lockBalances($this->variantIds($lines));
            if ($this->claim($mutation->operationKey, $mutation->intentId, $action, $this->hash($action, $mutation->intentId, $lines))) {
                return $this->receipt($mutation->intentId);
            }
            $now = now();
            foreach ($lines as $line) {
                $reservation = DB::table('stock_reservations')
                    ->where('intent_id', $mutation->intentId)
                    ->where('variant_id', $line->variantId)
                    ->lockForUpdate()
                    ->first();
                $status = $this->stringValue($reservation, 'status');
                if ($reservation === null || $this->intValue($reservation, 'remaining_qty') < $line->quantity) {
                    throw new CatalogInvalidAllocation;
                }
                if ($action === 'ship' && $status !== 'committed') {
                    throw new CatalogInvalidAllocation;
                }
                if ($action === 'release' && ! in_array($status, ['active', 'committed'], true)) {
                    throw new CatalogInvalidAllocation;
                }
                $shipped = $this->intValue($reservation, 'shipped_qty') + ($action === 'ship' ? $line->quantity : 0);
                $released = $this->intValue($reservation, 'released_qty') + ($action === 'release' ? $line->quantity : 0);
                $remaining = $this->intValue($reservation, 'remaining_qty') - $line->quantity;
                $nextStatus = $remaining > 0 ? $status : ($status === 'active' ? 'released' : 'closed');
                $reservationId = $this->stringValue($reservation, 'id');
                DB::table('stock_reservations')->where('id', $reservationId)->update([
                    'shipped_qty' => $shipped,
                    'released_qty' => $released,
                    'remaining_qty' => $remaining,
                    'status' => $nextStatus,
                    'expires_at' => $nextStatus === 'active' ? (get_object_vars($reservation)['expires_at'] ?? null) : null,
                    'updated_at' => $now,
                ]);
                $onHandDelta = $action === 'ship' ? 0 - $line->quantity : 0;
                $reservedDelta = 0 - $line->quantity;
                $balance = $this->lockedTrackedBalance($line->variantId);
                DB::table('inventory_balances')->where('variant_id', $line->variantId)->update([
                    'on_hand' => $this->intValue($balance, 'on_hand') + $onHandDelta,
                    'reserved' => $this->intValue($balance, 'reserved') + $reservedDelta,
                    'updated_at' => $now,
                ]);
                $this->move($action.':'.$mutation->operationKey.':'.$line->variantId, $line->variantId, $reservationId, $onHandDelta, $reservedDelta, $action, $now);
            }

            return $this->receipt($mutation->intentId);
        });
    }

    /**
     * @param  list<InventoryLine>  $lines
     * @return list<InventoryLine>
     */
    private function sorted(array $lines): array
    {
        usort($lines, fn (InventoryLine $left, InventoryLine $right): int => $left->variantId <=> $right->variantId);

        return $lines;
    }

    /**
     * @param  list<InventoryLine>  $lines
     * @return list<string>
     */
    private function variantIds(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            $ids[] = $line->variantId;
        }

        return $ids;
    }

    /** @param list<string> $variantIds */
    private function lockBalances(array $variantIds): void
    {
        $ids = array_values(array_unique($variantIds));
        sort($ids);
        if ($ids === []) {
            return;
        }
        DB::table('inventory_balances')->whereIn('variant_id', $ids)->orderBy('variant_id')->lockForUpdate()->get();
    }

    /** @return list<object> */
    private function lockIntent(string $intentId): array
    {
        $rows = DB::table('stock_reservations')->where('intent_id', $intentId)->orderBy('variant_id')->lockForUpdate()->get();
        if ($rows->isEmpty()) {
            throw new CatalogInvalidAllocation;
        }
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $this->stringValue($row, 'variant_id');
        }
        $this->lockBalances($ids);

        return array_values($rows->all());
    }

    private function lockedTrackedBalance(string $variantId): object
    {
        $variant = DB::table('product_variants')->where('id', $variantId)->lockForUpdate()->first();
        $balance = DB::table('inventory_balances')->where('variant_id', $variantId)->lockForUpdate()->first();
        if ($variant === null || $balance === null || ! $this->boolValue($variant, 'tracks_inventory')) {
            throw new CatalogInventoryUntracked;
        }

        return $balance;
    }

    private function claim(string $operationKey, string $intentId, string $action, string $hash): bool
    {
        try {
            DB::table('inventory_operations')->insert([
                'operation_key' => $operationKey,
                'intent_id' => $intentId,
                'action' => $action,
                'payload_hash' => $hash,
                'created_at' => now(),
            ]);

            return false;
        } catch (UniqueConstraintViolationException) {
            $existing = DB::table('inventory_operations')->where('operation_key', $operationKey)->lockForUpdate()->first();
            if ($existing === null
                || $this->stringValue($existing, 'intent_id') !== $intentId
                || $this->stringValue($existing, 'action') !== $action
                || $this->stringValue($existing, 'payload_hash') !== $hash) {
                throw new CatalogInventoryConflict;
            }

            return true;
        }
    }

    /** @param list<InventoryLine> $lines */
    private function hash(string $action, string $intentId, array $lines): string
    {
        $payload = ['action' => $action, 'intent_id' => $intentId, 'lines' => []];
        foreach ($lines as $line) {
            $payload['lines'][] = ['variant_id' => $line->variantId, 'quantity' => $line->quantity];
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function move(string $sourceRef, string $variantId, ?string $reservationId, int $onHand, int $reserved, string $reason, mixed $now): void
    {
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(),
            'source_ref' => $sourceRef,
            'variant_id' => $variantId,
            'reservation_id' => $reservationId,
            'on_hand_delta' => $onHand,
            'reserved_delta' => $reserved,
            'reason' => $reason,
            'created_at' => $now,
        ]);
    }

    private function receipt(string $intentId): InventoryReceipt
    {
        $allocations = [];
        foreach (DB::table('stock_reservations')->where('intent_id', $intentId)->orderBy('variant_id')->get() as $row) {
            $allocations[] = new InventoryAllocation(
                $this->stringValue($row, 'id'),
                $this->stringValue($row, 'variant_id'),
                $this->intValue($row, 'original_qty'),
                $this->intValue($row, 'shipped_qty'),
                $this->intValue($row, 'released_qty'),
                $this->intValue($row, 'remaining_qty'),
                $this->stringValue($row, 'status'),
            );
        }

        return new InventoryReceipt($intentId, $allocations);
    }

    private function intValue(?object $row, string $key): int
    {
        $value = $row === null ? null : (get_object_vars($row)[$key] ?? null);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function stringValue(?object $row, string $key): string
    {
        $value = $row === null ? null : (get_object_vars($row)[$key] ?? null);

        return is_scalar($value) ? (string) $value : '';
    }

    private function boolValue(object $row, string $key): bool
    {
        $value = get_object_vars($row)[$key] ?? false;

        return $value === true || $value === 1 || $value === '1';
    }
}
