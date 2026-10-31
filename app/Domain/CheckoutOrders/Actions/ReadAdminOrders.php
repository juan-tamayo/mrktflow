<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\CheckoutOrders\Support\CartMoney;
use App\Domain\Identity\Models\StaffAccount;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReadAdminOrders
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, next_page_url: ?string, prev_page_url: ?string, q: string, from: string, to: string}
     */
    public function search(StaffAccount $actor, array $filters): array
    {
        $this->authorize($actor);
        $q = $this->term($filters['q'] ?? null);
        $from = $this->day($filters['from'] ?? null, false);
        $to = $this->day($filters['to'] ?? null, true);
        $query = DB::table('orders')->orderByDesc('created_at')->orderByDesc('id');
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(function (Builder $orders) use ($like): void {
                $orders->where('number', 'like', $like)->orWhere('email', 'like', $like);
            });
        }
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }
        $page = $query->paginate(24)->withQueryString();
        $data = [];
        foreach ($page->getCollection() as $row) {
            $data[] = $this->summary($row);
        }

        return [
            'data' => $data,
            'next_page_url' => $page->nextPageUrl(),
            'prev_page_url' => $page->previousPageUrl(),
            'q' => $q,
            'from' => is_string($filters['from'] ?? null) ? trim((string) $filters['from']) : '',
            'to' => is_string($filters['to'] ?? null) ? trim((string) $filters['to']) : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(StaffAccount $actor, string $orderId): array
    {
        $this->authorize($actor);
        $order = DB::table('orders')->where('id', $orderId)->first();
        if ($order === null) {
            throw new NotFoundHttpException;
        }
        $currency = $this->string($order, 'currency');
        $exponent = $this->int($order, 'exponent');
        $returnTotals = $this->returnTotals($orderId);
        $lines = [];
        foreach (DB::table('order_lines')->where('order_id', $orderId)->orderBy('sku')->orderBy('id')->get() as $line) {
            $available = $this->int($line, 'quantity') - $this->int($line, 'shipped_qty')
                - $this->int($line, 'cancelled_qty') - $this->int($line, 'held_qty');
            $totals = $returnTotals[$this->string($line, 'id')] ?? ['received' => 0, 'restocked' => 0, 'committed' => 0];
            $lines[] = [
                'id' => $this->string($line, 'id'),
                'type' => $this->string($line, 'type'),
                'sku' => $this->string($line, 'sku'),
                'title' => $this->string($line, 'title'),
                'quantity' => $this->int($line, 'quantity'),
                'refundable_quantity' => max(0, $available),
                'held_qty' => $this->int($line, 'held_qty'),
                'cancelled_qty' => $this->int($line, 'cancelled_qty'),
                'shipped_qty' => $this->int($line, 'shipped_qty'),
                'shippable_quantity' => $this->string($line, 'type') === 'physical' ? max(0, $available) : 0,
                'returned_qty' => $totals['received'],
                'restocked_qty' => $totals['restocked'],
                'returnable_quantity' => $this->string($line, 'type') === 'physical'
                    ? max(0, $this->int($line, 'shipped_qty') - $totals['committed']) : 0,
                'subtotal' => CartMoney::format($this->int($line, 'subtotal_amount'), $currency, $exponent),
                'discount' => CartMoney::format($this->int($line, 'discount_amount'), $currency, $exponent),
                'total' => CartMoney::format($this->int($line, 'total_amount'), $currency, $exponent),
            ];
        }
        $notes = [];
        foreach (DB::table('order_notes')->where('order_id', $orderId)->orderBy('created_at')->orderBy('id')->get() as $note) {
            $staff = DB::table('staff_accounts')->where('id', $this->string($note, 'staff_id'))->first();
            $notes[] = [
                'id' => $this->string($note, 'id'),
                'body' => $this->string($note, 'body'),
                'staff_name' => $staff === null ? '' : $this->string($staff, 'name'),
                'created_at' => $this->iso($this->string($note, 'created_at')),
            ];
        }

        return [
            'id' => $this->string($order, 'id'),
            'number' => $this->string($order, 'number'),
            'email' => $this->string($order, 'email'),
            'status' => $this->string($order, 'status'),
            'financial_status' => $this->string($order, 'financial_status'),
            'fulfillment_status' => $this->string($order, 'fulfillment_status'),
            'download_status' => $this->string($order, 'download_status'),
            'dispute_status' => $this->string($order, 'dispute_status'),
            'created_at' => $this->iso($this->string($order, 'created_at')),
            'coupon_code' => $this->nullable($order, 'coupon_code'),
            'totals' => [
                'merchandise' => CartMoney::format($this->int($order, 'merchandise_amount'), $currency, $exponent),
                'discount' => CartMoney::format($this->int($order, 'discount_amount'), $currency, $exponent),
                'shipping' => CartMoney::format($this->int($order, 'shipping_amount'), $currency, $exponent),
                'tax' => CartMoney::format($this->int($order, 'tax_amount'), $currency, $exponent),
                'grand_total' => CartMoney::format($this->int($order, 'grand_total_amount'), $currency, $exponent),
            ],
            'lines' => $lines,
            'billing' => $this->address($orderId, 'billing'),
            'shipping' => $this->address($orderId, 'shipping'),
            'notes' => $notes,
            'refunds' => $this->refunds($orderId, $currency, $exponent),
            'disputes' => $this->disputes($orderId, $currency, $exponent),
            'shipments' => $this->shipments($orderId),
            'returns' => $this->returns($orderId, $currency, $exponent),
            'downloads' => $this->downloads($orderId),
            'refundable' => $this->refundable($order, $lines, $currency, $exponent),
            'cancellable' => $this->cancellable($order, $lines),
            'timeline' => $this->timeline($orderId, $currency, $exponent),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function timeline(string $orderId, string $currency, int $exponent): array
    {
        $rows = [];
        foreach (DB::table('order_events')->where('order_id', $orderId)->orderBy('created_at')->orderBy('id')->get() as $event) {
            $payload = $this->payload($event);
            $amount = is_int($payload['amount'] ?? null) ? $payload['amount'] : 0;
            $code = is_string($payload['currency'] ?? null) ? $payload['currency'] : $currency;
            $scale = is_int($payload['exponent'] ?? null) ? $payload['exponent'] : $exponent;
            $rows[] = [
                'id' => $this->string($event, 'id'),
                'type' => $this->string($event, 'type'),
                'created_at' => $this->iso($this->string($event, 'created_at')),
                'amount' => CartMoney::format($amount, $code, $scale),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function refunds(string $orderId, string $currency, int $exponent): array
    {
        $rows = [];
        foreach (DB::table('refunds')->where('order_id', $orderId)->orderBy('created_at')->orderBy('id')->get() as $refund) {
            $rows[] = [
                'id' => $this->string($refund, 'id'),
                'status' => $this->string($refund, 'status'),
                'kind' => $this->string($refund, 'kind'),
                'origin' => $this->string($refund, 'origin'),
                'reason' => $this->string($refund, 'reason'),
                'amount' => CartMoney::format($this->int($refund, 'amount'), $currency, $exponent),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function disputes(string $orderId, string $currency, int $exponent): array
    {
        $rows = [];
        foreach (DB::table('order_disputes')->where('order_id', $orderId)->orderBy('created_at')->orderBy('id')->get() as $dispute) {
            $rows[] = [
                'id' => $this->string($dispute, 'id'),
                'provider_dispute_id' => $this->string($dispute, 'provider_dispute_id'),
                'status' => $this->string($dispute, 'status'),
                'reason' => $this->nullable($dispute, 'reason'),
                'amount' => CartMoney::format($this->int($dispute, 'amount'), $currency, $exponent),
                'created_at' => $this->iso($this->string($dispute, 'created_at')),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shipments(string $orderId): array
    {
        $rows = [];
        foreach (DB::table('shipments')->where('order_id', $orderId)->orderBy('created_at')->orderBy('id')->get() as $shipment) {
            $lines = [];
            foreach (DB::table('shipment_lines')->where('shipment_id', $this->string($shipment, 'id'))->orderBy('order_line_id')->get() as $line) {
                $lines[] = [
                    'id' => $this->string($line, 'order_line_id'),
                    'quantity' => $this->int($line, 'quantity'),
                ];
            }
            $rows[] = [
                'id' => $this->string($shipment, 'id'),
                'carrier' => $this->nullable($shipment, 'carrier'),
                'tracking_number' => $this->nullable($shipment, 'tracking_number'),
                'tracking_url' => $this->nullable($shipment, 'tracking_url'),
                'created_at' => $this->iso($this->string($shipment, 'created_at')),
                'lines' => $lines,
            ];
        }

        return $rows;
    }

    /**
     * Per-line return aggregates: received/restocked totals plus the
     * committed quantity (rejected returns commit only what they received,
     * approved ones their approved quantities, requested ones their request).
     *
     * @return array<string, array{received: int, restocked: int, committed: int}>
     */
    private function returnTotals(string $orderId): array
    {
        $totals = [];
        $rows = DB::table('return_lines')
            ->join('order_returns', 'order_returns.id', '=', 'return_lines.return_id')
            ->where('order_returns.order_id', $orderId)
            ->get([
                'return_lines.order_line_id',
                'return_lines.requested_qty',
                'return_lines.approved_qty',
                'return_lines.received_qty',
                'return_lines.restocked_qty',
                'order_returns.status',
            ]);
        foreach ($rows as $row) {
            $lineId = $this->string($row, 'order_line_id');
            $status = $this->string($row, 'status');
            $received = $this->int($row, 'received_qty');
            $committed = match (true) {
                $status === 'rejected' => $received,
                $status === 'requested' => $this->int($row, 'requested_qty'),
                default => max($this->int($row, 'approved_qty'), $received),
            };
            $entry = $totals[$lineId] ?? ['received' => 0, 'restocked' => 0, 'committed' => 0];
            $entry['received'] += $received;
            $entry['restocked'] += $this->int($row, 'restocked_qty');
            $entry['committed'] += $committed;
            $totals[$lineId] = $entry;
        }

        return $totals;
    }

    /**
     * Return summary with refund linkage: the refund facts recorded against
     * each returned order line, read from the refund machinery. Returns
     * themselves record no money.
     *
     * @return list<array<string, mixed>>
     */
    private function returns(string $orderId, string $currency, int $exponent): array
    {
        $rows = [];
        foreach (DB::table('order_returns')->where('order_id', $orderId)->orderBy('created_at')->orderBy('id')->get() as $return) {
            $staff = DB::table('staff_accounts')->where('id', $this->string($return, 'staff_id'))->first();
            $lines = [];
            foreach (DB::table('return_lines')->where('return_id', $this->string($return, 'id'))->orderBy('order_line_id')->get() as $line) {
                $refunds = [];
                $refundRows = DB::table('refund_lines')
                    ->join('refunds', 'refunds.id', '=', 'refund_lines.refund_id')
                    ->where('refund_lines.order_line_id', $this->string($line, 'order_line_id'))
                    ->orderBy('refunds.created_at')
                    ->orderBy('refunds.id')
                    ->get(['refunds.id', 'refunds.kind', 'refunds.origin', 'refunds.status', 'refund_lines.quantity', 'refund_lines.amount']);
                foreach ($refundRows as $refund) {
                    $refunds[] = [
                        'id' => $this->string($refund, 'id'),
                        'kind' => $this->string($refund, 'kind'),
                        'origin' => $this->string($refund, 'origin'),
                        'status' => $this->string($refund, 'status'),
                        'quantity' => $this->int($refund, 'quantity'),
                        'amount' => CartMoney::format($this->int($refund, 'amount'), $currency, $exponent),
                    ];
                }
                $lines[] = [
                    'id' => $this->string($line, 'order_line_id'),
                    'requested' => $this->int($line, 'requested_qty'),
                    'approved' => $this->int($line, 'approved_qty'),
                    'received' => $this->int($line, 'received_qty'),
                    'restocked' => $this->int($line, 'restocked_qty'),
                    'refunds' => $refunds,
                ];
            }
            $rows[] = [
                'id' => $this->string($return, 'id'),
                'status' => $this->string($return, 'status'),
                'reason' => $this->string($return, 'reason'),
                'staff_name' => $staff === null ? '' : $this->string($staff, 'name'),
                'created_at' => $this->iso($this->string($return, 'created_at')),
                'lines' => $lines,
            ];
        }

        return $rows;
    }

    /**
     * Download access per entitlement (T049–T053): the version the
     * buyer is currently pinned to, the version frozen on the order
     * line at purchase, the allowance counters, and the strictly
     * newer ready versions of the same asset staff may grant. Storage
     * keys and file paths are never exposed.
     *
     * @return list<array<string, mixed>>
     */
    private function downloads(string $orderId): array
    {
        $rows = [];
        $entitlements = DB::table('entitlements')->where('order_id', $orderId)
            ->orderBy('created_at')->orderBy('id')->get();
        foreach ($entitlements as $entitlement) {
            $line = DB::table('order_lines')->where('id', $this->string($entitlement, 'order_line_id'))->first();
            $current = DB::table('digital_asset_versions')
                ->where('id', $this->string($entitlement, 'digital_asset_version_id'))->first();
            $purchase = $line === null ? null : DB::table('digital_asset_versions')
                ->where('id', $this->string($line, 'digital_asset_version_id'))->first();
            $limit = $this->int($entitlement, 'download_limit');
            $used = $this->int($entitlement, 'downloads_used');
            $available = [];
            if ($current !== null) {
                $versions = DB::table('digital_asset_versions')
                    ->where('digital_asset_id', $this->string($current, 'digital_asset_id'))
                    ->where('status', 'ready')
                    ->where('version', '>', $this->int($current, 'version'))
                    ->orderBy('version')->get();
                foreach ($versions as $version) {
                    $available[] = [
                        'id' => $this->string($version, 'id'),
                        'version' => $this->int($version, 'version'),
                        'bytes' => $this->int($version, 'byte_size'),
                    ];
                }
            }
            $rows[] = [
                'id' => $this->string($entitlement, 'id'),
                'line_id' => $this->string($entitlement, 'order_line_id'),
                'title' => $line === null ? '' : $this->string($line, 'title'),
                'sku' => $line === null ? '' : $this->string($line, 'sku'),
                'asset_label' => $current === null ? '' : $this->string($current, 'label'),
                'version_id' => $this->string($entitlement, 'digital_asset_version_id'),
                'version' => $current === null ? 0 : $this->int($current, 'version'),
                'purchase_version' => $purchase === null ? 0 : $this->int($purchase, 'version'),
                'status' => $this->string($entitlement, 'status'),
                'download_limit' => $limit,
                'downloads_used' => $used,
                'downloads_remaining' => max(0, $limit - $used),
                'available_versions' => $available,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function refundable(object $order, array $lines, string $currency, int $exponent): array
    {
        $captured = (int) (DB::table('payments')->where('order_id', $this->string($order, 'id'))->value('amount') ?? 0);
        $allocated = (int) DB::table('refunds')->where('order_id', $this->string($order, 'id'))
            ->whereIn('status', ['processing', 'unknown', 'succeeded'])
            ->sum('amount');
        $remaining = max(0, $captured - $allocated);
        $quantities = [];
        foreach ($lines as $line) {
            $quantities[] = [
                'id' => $line['id'],
                'quantity' => $line['refundable_quantity'],
            ];
        }

        return [
            'amount' => $remaining,
            'money' => CartMoney::format($remaining, $currency, $exponent),
            'lines' => $quantities,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function cancellable(object $order, array $lines): bool
    {
        if (! in_array($this->string($order, 'status'), ['confirmed', 'on_hold', 'completed'], true)) {
            return false;
        }
        foreach ($lines as $line) {
            $held = is_int($line['held_qty'] ?? null) ? $line['held_qty'] : 0;
            $quantity = is_int($line['quantity'] ?? null) ? $line['quantity'] : 0;
            $refundable = is_int($line['refundable_quantity'] ?? null) ? $line['refundable_quantity'] : 0;
            $cancelled = is_int($line['cancelled_qty'] ?? null) ? $line['cancelled_qty'] : 0;
            if ($held > 0 || $quantity - $refundable - $held - $cancelled > 0) {
                return false;
            }
        }

        return true;
    }

    private function authorize(StaffAccount $actor): void
    {
        if (! $actor->hasPermission('orders.read')) {
            throw new AuthorizationException;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(object $row): array
    {
        $currency = $this->string($row, 'currency');
        $exponent = $this->int($row, 'exponent');

        return [
            'id' => $this->string($row, 'id'),
            'number' => $this->string($row, 'number'),
            'email' => $this->string($row, 'email'),
            'status' => $this->string($row, 'status'),
            'financial_status' => $this->string($row, 'financial_status'),
            'fulfillment_status' => $this->string($row, 'fulfillment_status'),
            'download_status' => $this->string($row, 'download_status'),
            'created_at' => $this->iso($this->string($row, 'created_at')),
            'grand_total' => CartMoney::format($this->int($row, 'grand_total_amount'), $currency, $exponent),
        ];
    }

    /** @return array<string, string>|null */
    private function address(string $orderId, string $kind): ?array
    {
        $row = DB::table('order_addresses')->where('order_id', $orderId)->where('kind', $kind)->first();
        if ($row === null) {
            return null;
        }

        return [
            'recipient' => $this->string($row, 'recipient'),
            'line1' => $this->string($row, 'line1'),
            'line2' => $this->nullable($row, 'line2') ?? '',
            'city' => $this->string($row, 'city'),
            'region' => $this->nullable($row, 'region') ?? '',
            'postal_code' => $this->nullable($row, 'postal_code') ?? '',
            'country_code' => $this->string($row, 'country_code'),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(object $event): array
    {
        $raw = get_object_vars($event)['payload'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function term(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return mb_substr(trim($value), 0, 191);
    }

    private function day(mixed $value, bool $end): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            return null;
        }
        $time = $end ? 'T23:59:59' : 'T00:00:00';
        $date = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s', trim($value).$time, new DateTimeZone('UTC'));

        return $date instanceof DateTimeImmutable ? $date->format('Y-m-d H:i:s') : null;
    }

    private function iso(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));

        return $date->format('Y-m-d\\TH:i:s\\Z');
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
}
