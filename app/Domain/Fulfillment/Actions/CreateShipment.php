<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\Catalog\Contracts\InventoryAllocator;
use App\Domain\Catalog\Data\InventoryLine;
use App\Domain\Catalog\Data\InventoryMutation;
use App\Domain\CheckoutOrders\Actions\RecordOrderEvent;
use App\Domain\CheckoutOrders\Support\OrderStates;
use App\Domain\Fulfillment\Exceptions\FulfillmentIdempotencyConflict;
use App\Domain\Fulfillment\Rules\TrackingUrl;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreateShipment
{
    public const OPERATION = 'order-shipment';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(StaffAccount $actor, string $orderId, array $input, string $idempotencyKey): array
    {
        if (! $actor->hasPermission('fulfillment.manage')) {
            throw new AuthorizationException;
        }
        if (! Str::isUuid($orderId) || $orderId !== strtolower($orderId)
            || ! Str::isUuid($idempotencyKey) || $idempotencyKey !== strtolower($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Send a stable shipment key.']);
        }

        return DB::transaction(function () use ($actor, $orderId, $input, $idempotencyKey): array {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new NotFoundHttpException;
            }
            $normalized = $this->normalized($input);
            $hash = hash('sha256', json_encode([
                'order_id' => $orderId,
                'carrier' => $normalized['carrier'],
                'tracking_number' => $normalized['tracking_number'],
                'tracking_url' => $normalized['tracking_url'],
                'lines' => $normalized['lines'],
            ], JSON_THROW_ON_ERROR));
            $claim = $this->idempotency($actor, $idempotencyKey, $hash);
            if ($claim['replay']) {
                $shipmentId = $claim['resource_id'];
                if ($shipmentId === null) {
                    throw new FulfillmentIdempotencyConflict;
                }

                return $this->view($shipmentId);
            }
            if (! in_array($this->string($order, 'status'), ['confirmed', 'completed'], true)
                || in_array($this->string($order, 'financial_status'), ['unpaid', 'unknown'], true)) {
                throw ValidationException::withMessages(['order' => 'This order has no captured payment to ship against.']);
            }
            $byId = [];
            foreach (DB::table('order_lines')->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get() as $row) {
                $byId[$this->string($row, 'id')] = $row;
            }
            $shipLines = [];
            foreach ($normalized['lines'] as $item) {
                $row = $byId[$item['id']] ?? null;
                if ($row === null || $this->string($row, 'type') !== 'physical') {
                    throw ValidationException::withMessages(['lines' => 'Shipment lines must be physical lines on this order.']);
                }
                $available = $this->int($row, 'quantity') - $this->int($row, 'shipped_qty')
                    - $this->int($row, 'cancelled_qty') - $this->int($row, 'held_qty');
                if ($item['quantity'] > $available) {
                    throw ValidationException::withMessages(['lines' => 'Shipment quantities exceed the paid unshipped quantities.']);
                }
                $shipLines[] = ['row' => $row, 'quantity' => $item['quantity']];
            }
            $now = now();
            $shipmentId = (string) Str::uuid();
            DB::table('shipments')->insert([
                'id' => $shipmentId,
                'order_id' => $orderId,
                'staff_id' => $actor->id,
                'carrier' => $normalized['carrier'],
                'tracking_number' => $normalized['tracking_number'],
                'tracking_url' => $normalized['tracking_url'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($shipLines as $shipLine) {
                $row = $shipLine['row'];
                DB::table('shipment_lines')->insert([
                    'id' => (string) Str::uuid(),
                    'shipment_id' => $shipmentId,
                    'order_line_id' => $this->string($row, 'id'),
                    'quantity' => $shipLine['quantity'],
                ]);
                DB::table('order_lines')->where('id', $this->string($row, 'id'))->update([
                    'shipped_qty' => $this->int($row, 'shipped_qty') + $shipLine['quantity'],
                    'eligibility_revision' => $this->int($row, 'eligibility_revision') + 1,
                ]);
            }
            $this->consumeStock($orderId, $shipmentId, $shipLines);
            $this->project($orderId);
            app(RecordOrderEvent::class)->record($orderId, 'shipment_created', [
                'shipment_id' => $shipmentId,
                'carrier' => $normalized['carrier'],
                'tracking_number' => $normalized['tracking_number'],
                'lines' => $normalized['lines'],
            ], $shipmentId);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-shipment:'.$shipmentId, [
                'action' => 'fulfillment.shipment.created',
                'subject_type' => 'order',
                'subject_id' => $orderId,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['version' => 1],
            ]);
            app(QueueShipmentNotification::class)->queue($shipmentId);
            DB::table('idempotency_records')->where('id', $claim['id'])->update([
                'status' => 'completed',
                'resource_id' => $shipmentId,
                'updated_at' => $now,
            ]);

            return $this->view($shipmentId);
        });
    }

    /**
     * @param  list<array{row: object, quantity: int}>  $shipLines
     */
    private function consumeStock(string $orderId, string $shipmentId, array $shipLines): void
    {
        $reservations = [];
        foreach (DB::table('stock_reservations')->where('intent_id', $orderId)->lockForUpdate()->get() as $row) {
            $reservations[$this->string($row, 'variant_id')] = $row;
        }
        $quantities = [];
        foreach ($shipLines as $shipLine) {
            $variantId = $this->string($shipLine['row'], 'variant_id');
            if (! isset($reservations[$variantId])) {
                $variant = DB::table('product_variants')->where('id', $variantId)->first();
                if ($variant !== null && $this->bool($variant, 'tracks_inventory')) {
                    throw ValidationException::withMessages(['lines' => 'Tracked stock for a shipment line is not allocated to this order.']);
                }

                continue;
            }
            $quantities[$variantId] = ($quantities[$variantId] ?? 0) + $shipLine['quantity'];
        }
        if ($quantities === []) {
            return;
        }
        $lines = [];
        foreach ($quantities as $variantId => $quantity) {
            $lines[] = new InventoryLine($variantId, $quantity);
        }
        usort($lines, fn (InventoryLine $left, InventoryLine $right): int => $left->variantId <=> $right->variantId);
        app(InventoryAllocator::class)->ship(new InventoryMutation(
            $orderId,
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'mrktflow:shipment-stock:'.$shipmentId)->toString(),
            $lines,
        ));
    }

    private function project(string $orderId): void
    {
        $total = 0;
        $shipped = 0;
        foreach (DB::table('order_lines')->where('order_id', $orderId)->where('type', 'physical')->get() as $row) {
            $total += max(0, $this->int($row, 'quantity') - $this->int($row, 'cancelled_qty'));
            $shipped += $this->int($row, 'shipped_qty');
        }
        if ($total === 0) {
            return;
        }
        $status = $shipped === 0 ? 'unfulfilled' : ($shipped >= $total ? 'fulfilled' : 'partially_fulfilled');
        OrderStates::assertFulfillment($status);
        DB::table('orders')->where('id', $orderId)->update([
            'fulfillment_status' => $status,
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{carrier: ?string, tracking_number: ?string, tracking_url: ?string, lines: list<array{id: string, quantity: int}>}
     */
    private function normalized(array $input): array
    {
        $carrier = $this->optionalText($input['carrier'] ?? null, 120, 'carrier', 'Carrier');
        $trackingNumber = $this->optionalText($input['tracking_number'] ?? null, 191, 'tracking_number', 'Tracking number');
        $trackingUrl = TrackingUrl::normalize($input['tracking_url'] ?? null);
        $posted = $input['lines'] ?? [];
        if (! is_array($posted)) {
            throw ValidationException::withMessages(['lines' => 'Choose shipment quantities from order lines.']);
        }
        $quantities = [];
        foreach ($posted as $line) {
            if (! is_array($line) || ! is_string($line['id'] ?? null) || ! Str::isUuid((string) $line['id'])) {
                continue;
            }
            $quantity = $line['quantity'] ?? 0;
            if (! is_int($quantity) && ! (is_string($quantity) && preg_match('/^\d+$/', $quantity) === 1)) {
                throw ValidationException::withMessages(['lines' => 'Shipment quantities must be whole numbers.']);
            }
            $quantity = (int) $quantity;
            if ($quantity < 1) {
                continue;
            }
            $id = strtolower((string) $line['id']);
            $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
        }
        if ($quantities === []) {
            throw ValidationException::withMessages(['lines' => 'Choose shipment quantities from order lines.']);
        }
        $lines = [];
        foreach ($quantities as $id => $quantity) {
            $lines[] = ['id' => $id, 'quantity' => $quantity];
        }
        usort($lines, fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return [
            'carrier' => $carrier,
            'tracking_number' => $trackingNumber,
            'tracking_url' => $trackingUrl,
            'lines' => $lines,
        ];
    }

    private function optionalText(mixed $value, int $max, string $field, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw ValidationException::withMessages([$field => $label.' must be text.']);
        }
        $text = trim($value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$field => $label.' is too long.']);
        }

        return $text;
    }

    /**
     * @return array{id: string, replay: bool, resource_id: ?string}
     */
    private function idempotency(StaffAccount $actor, string $key, string $hash): array
    {
        $scope = 'staff:'.$actor->id;
        try {
            $id = (string) Str::uuid();
            DB::table('idempotency_records')->insert([
                'id' => $id,
                'principal_scope' => $scope,
                'operation' => self::OPERATION,
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
                ->where('operation', self::OPERATION)
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

    /**
     * @return array<string, mixed>
     */
    private function view(string $shipmentId): array
    {
        $shipment = DB::table('shipments')->where('id', $shipmentId)->first();
        if ($shipment === null) {
            throw new NotFoundHttpException;
        }
        $lines = [];
        foreach (DB::table('shipment_lines')->where('shipment_id', $shipmentId)->orderBy('order_line_id')->get() as $row) {
            $lines[] = ['id' => $this->string($row, 'order_line_id'), 'quantity' => $this->int($row, 'quantity')];
        }

        return [
            'id' => $this->string($shipment, 'id'),
            'order_id' => $this->string($shipment, 'order_id'),
            'carrier' => $this->nullable($shipment, 'carrier'),
            'tracking_number' => $this->nullable($shipment, 'tracking_number'),
            'tracking_url' => $this->nullable($shipment, 'tracking_url'),
            'lines' => $lines,
        ];
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function nullable(object $row, string $key): ?string
    {
        $value = $this->string($row, $key);

        return $value === '' ? null : $value;
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function bool(object $row, string $key): bool
    {
        $value = get_object_vars($row)[$key] ?? false;

        return $value === true || $value === 1 || $value === '1';
    }
}
