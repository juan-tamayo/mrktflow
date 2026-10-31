<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\Fulfillment\Exceptions\FulfillmentIdempotencyConflict;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class BaseReturnAction
{
    protected function authorize(StaffAccount $actor): void
    {
        if (! $actor->hasPermission('fulfillment.manage')) {
            throw new AuthorizationException;
        }
    }

    protected function assertKey(string $idempotencyKey): void
    {
        if (! Str::isUuid($idempotencyKey) || $idempotencyKey !== strtolower($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Send a stable return key.']);
        }
    }

    protected function assertId(string $id): void
    {
        if (! Str::isUuid($id) || $id !== strtolower($id)) {
            throw new NotFoundHttpException;
        }
    }

    protected function lockOrder(string $orderId): object
    {
        $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        if ($order === null) {
            throw new NotFoundHttpException;
        }

        return $order;
    }

    /** @return array<string, object> */
    protected function lockOrderLines(string $orderId): array
    {
        $byId = [];
        foreach (DB::table('order_lines')->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get() as $row) {
            $byId[$this->string($row, 'id')] = $row;
        }

        return $byId;
    }

    protected function lockReturn(string $orderId, string $returnId): object
    {
        $return = DB::table('order_returns')->where('id', $returnId)->where('order_id', $orderId)->lockForUpdate()->first();
        if ($return === null) {
            throw new NotFoundHttpException;
        }

        return $return;
    }

    /** @return array<string, object> */
    protected function lockReturnLines(string $returnId): array
    {
        $byId = [];
        foreach (DB::table('return_lines')->where('return_id', $returnId)->orderBy('order_line_id')->lockForUpdate()->get() as $row) {
            $byId[$this->string($row, 'order_line_id')] = $row;
        }

        return $byId;
    }

    /**
     * Committed quantities per order line: units a return already accounts
     * for. A rejected return commits only what it received; an approved one
     * commits its approved quantities; a requested one commits its request.
     *
     * @return array<string, int>
     */
    protected function committedQuantities(string $orderId): array
    {
        $committed = [];
        $rows = DB::table('return_lines')
            ->join('order_returns', 'order_returns.id', '=', 'return_lines.return_id')
            ->where('order_returns.order_id', $orderId)
            ->get([
                'return_lines.order_line_id',
                'return_lines.requested_qty',
                'return_lines.approved_qty',
                'return_lines.received_qty',
                'order_returns.status',
            ]);
        foreach ($rows as $row) {
            $status = $this->string($row, 'status');
            $received = $this->int($row, 'received_qty');
            $quantity = match (true) {
                $status === 'rejected' => $received,
                $status === 'requested' => $this->int($row, 'requested_qty'),
                default => max($this->int($row, 'approved_qty'), $received),
            };
            $lineId = $this->string($row, 'order_line_id');
            $committed[$lineId] = ($committed[$lineId] ?? 0) + $quantity;
        }

        return $committed;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{id: string, quantity: int}>
     */
    protected function quantities(array $input, bool $allowEmpty): array
    {
        $posted = $input['lines'] ?? [];
        if (! is_array($posted)) {
            throw ValidationException::withMessages(['lines' => 'Choose return quantities from order lines.']);
        }
        $quantities = [];
        foreach ($posted as $line) {
            if (! is_array($line) || ! is_string($line['id'] ?? null) || ! Str::isUuid((string) $line['id'])) {
                continue;
            }
            $quantity = $line['quantity'] ?? 0;
            if (! is_int($quantity) && ! (is_string($quantity) && preg_match('/^\d+$/', $quantity) === 1)) {
                throw ValidationException::withMessages(['lines' => 'Return quantities must be whole numbers.']);
            }
            $quantity = (int) $quantity;
            if ($quantity < 1) {
                continue;
            }
            $id = strtolower((string) $line['id']);
            $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
        }
        if ($quantities === [] && ! $allowEmpty) {
            throw ValidationException::withMessages(['lines' => 'Choose return quantities from order lines.']);
        }
        $lines = [];
        foreach ($quantities as $id => $quantity) {
            $lines[] = ['id' => $id, 'quantity' => $quantity];
        }
        usort($lines, fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $lines;
    }

    /**
     * @return array{id: string, replay: bool, resource_id: ?string}
     */
    protected function idempotency(StaffAccount $actor, string $operation, string $key, string $hash): array
    {
        $scope = 'staff:'.$actor->id;
        try {
            $id = (string) Str::uuid();
            DB::table('idempotency_records')->insert([
                'id' => $id,
                'principal_scope' => $scope,
                'operation' => $operation,
                'idempotency_key' => $key,
                'payload_hash' => $hash,
                'status' => 'processing',
                'resource_id' => null,
                'attempt_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['id' => $id, 'replay' => false, 'resource_id' => null];
        } catch (UniqueConstraintViolationException) {
            $existing = DB::table('idempotency_records')
                ->where('principal_scope', $scope)
                ->where('operation', $operation)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($existing === null) {
                throw new FulfillmentIdempotencyConflict;
            }
            if ($this->string($existing, 'payload_hash') !== $hash) {
                throw new FulfillmentIdempotencyConflict;
            }

            return [
                'id' => $this->string($existing, 'id'),
                'replay' => true,
                'resource_id' => $this->nullable($existing, 'resource_id'),
            ];
        }
    }

    protected function finishClaim(string $claimId, string $returnId): void
    {
        DB::table('idempotency_records')->where('id', $claimId)->update([
            'status' => 'completed',
            'resource_id' => $returnId,
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function view(string $returnId): array
    {
        $return = DB::table('order_returns')->where('id', $returnId)->first();
        if ($return === null) {
            throw new NotFoundHttpException;
        }
        $lines = [];
        foreach (DB::table('return_lines')->where('return_id', $returnId)->orderBy('order_line_id')->get() as $row) {
            $lines[] = [
                'id' => $this->string($row, 'order_line_id'),
                'requested' => $this->int($row, 'requested_qty'),
                'approved' => $this->int($row, 'approved_qty'),
                'received' => $this->int($row, 'received_qty'),
                'restocked' => $this->int($row, 'restocked_qty'),
            ];
        }

        return [
            'id' => $this->string($return, 'id'),
            'order_id' => $this->string($return, 'order_id'),
            'status' => $this->string($return, 'status'),
            'reason' => $this->string($return, 'reason'),
            'lines' => $lines,
        ];
    }

    protected function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    protected function nullable(object $row, string $key): ?string
    {
        $value = $this->string($row, $key);

        return $value === '' ? null : $value;
    }

    protected function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
