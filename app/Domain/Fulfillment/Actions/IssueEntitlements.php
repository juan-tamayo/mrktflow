<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\CheckoutOrders\Actions\RecordOrderEvent;
use App\Domain\Platform\Data\OutboxEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Issues the durable entitlement facts behind digital delivery (FUL-04).
 *
 * The paid event is only a trigger: eligibility is always rechecked against
 * the current authoritative order, line and dispute state under row locks,
 * so a replayed or delayed OrderPaid can never grant access that a refund,
 * cancellation or dispute has already taken away. Lines that are no longer
 * eligible still receive a row in the terminal state their facts dictate —
 * a missing entitlement row is never permission to grant later.
 */
final class IssueEntitlements
{
    /**
     * @return list<array{id: string, order_line_id: string, digital_asset_version_id: string, status: string, created: bool}>
     */
    public function issueFromPaidEvent(OutboxEvent $event): array
    {
        $orderId = $event->payload['order_id'] ?? null;
        if (! is_string($orderId) || ! Str::isUuid($orderId)) {
            throw new InvalidArgumentException('Invalid paid order for entitlements.');
        }
        $issued = $this->issueForOrder($orderId, $event->id);
        $created = array_values(array_filter($issued, static fn (array $row): bool => $row['created']));
        if ($created !== []) {
            $counts = ['active' => 0, 'suspended' => 0, 'revoked' => 0];
            foreach ($created as $row) {
                if (isset($counts[$row['status']])) {
                    $counts[$row['status']]++;
                }
            }
            app(RecordOrderEvent::class)->record($orderId, 'entitlements_issued', [
                'entitlements' => count($created),
                'active' => $counts['active'],
                'suspended' => $counts['suspended'],
                'revoked' => $counts['revoked'],
            ], $event->id);
        }

        return $issued;
    }

    /**
     * @return list<array{id: string, order_line_id: string, digital_asset_version_id: string, status: string, created: bool}>
     */
    public function issueForOrder(string $orderId, ?string $sourceEventId): array
    {
        if (! Str::isUuid($orderId) || $orderId !== strtolower($orderId)
            || ($sourceEventId !== null && (! Str::isUuid($sourceEventId) || $sourceEventId !== strtolower($sourceEventId)))) {
            throw new InvalidArgumentException('Invalid entitlement issuance.');
        }

        return DB::transaction(function () use ($orderId, $sourceEventId): array {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new InvalidArgumentException('Order is missing.');
            }
            // Grants fire only off a recorded payment fact: a captured
            // provider payment or the local zero-total completion row.
            if (! DB::table('payments')->where('order_id', $orderId)->exists()) {
                throw new InvalidArgumentException('Order has no recorded payment.');
            }
            $lines = DB::table('order_lines')->where('order_id', $orderId)
                ->where('type', 'digital')->whereNotNull('digital_asset_version_id')
                ->orderBy('id')->lockForUpdate()->get();
            $issued = [];
            $grantedActive = false;
            foreach ($lines as $line) {
                $lineId = $this->string($line, 'id');
                $existing = DB::table('entitlements')->where('order_line_id', $lineId)->lockForUpdate()->first();
                if ($existing !== null) {
                    $issued[] = $this->row($existing, false);

                    continue;
                }
                $status = $this->eligibility($order, $line);
                $id = (string) Str::uuid();
                $now = now();
                try {
                    DB::table('entitlements')->insert([
                        'id' => $id,
                        'order_id' => $orderId,
                        'order_line_id' => $lineId,
                        'customer_id' => $this->nullableString($order, 'customer_id'),
                        'digital_asset_version_id' => $this->string($line, 'digital_asset_version_id'),
                        'status' => $status,
                        'source_event_id' => $sourceEventId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    $existing = DB::table('entitlements')->where('order_line_id', $lineId)->first();
                    if ($existing === null) {
                        throw new InvalidArgumentException('Entitlement could not be recorded.');
                    }
                    $issued[] = $this->row($existing, false);

                    continue;
                }
                $grantedActive = $grantedActive || $status === 'active';
                $issued[] = [
                    'id' => $id,
                    'order_line_id' => $lineId,
                    'digital_asset_version_id' => $this->string($line, 'digital_asset_version_id'),
                    'status' => $status,
                    'created' => true,
                ];
            }
            // Only ever lift a still-locked projection; a suspended or
            // revoked download state is never un-suspended by issuance.
            if ($grantedActive && $this->string($order, 'download_status') === 'locked') {
                DB::table('orders')->where('id', $orderId)->update([
                    'download_status' => 'entitled',
                    'updated_at' => now(),
                ]);
            }

            return $issued;
        });
    }

    private function eligibility(object $order, object $line): string
    {
        if ($this->string($order, 'status') === 'cancelled'
            || $this->int($line, 'refunded_qty') >= $this->int($line, 'quantity')
            || $this->string($order, 'financial_status') === 'refunded'
            || $this->string($order, 'download_status') === 'revoked') {
            return 'revoked';
        }
        if ($this->string($order, 'dispute_status') === 'open'
            || $this->string($order, 'download_status') === 'suspended'
            || DB::table('order_disputes')->where('order_id', $this->string($order, 'id'))
                ->where('status', 'opened')->exists()) {
            return 'suspended';
        }

        return 'active';
    }

    /**
     * @return array{id: string, order_line_id: string, digital_asset_version_id: string, status: string, created: bool}
     */
    private function row(object $entitlement, bool $created): array
    {
        return [
            'id' => $this->string($entitlement, 'id'),
            'order_line_id' => $this->string($entitlement, 'order_line_id'),
            'digital_asset_version_id' => $this->string($entitlement, 'digital_asset_version_id'),
            'status' => $this->string($entitlement, 'status'),
            'created' => $created,
        ];
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableString(object $row, string $key): ?string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
