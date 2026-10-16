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
        $lines = [];
        foreach (DB::table('order_lines')->where('order_id', $orderId)->orderBy('sku')->orderBy('id')->get() as $line) {
            $lines[] = [
                'id' => $this->string($line, 'id'),
                'type' => $this->string($line, 'type'),
                'sku' => $this->string($line, 'sku'),
                'title' => $this->string($line, 'title'),
                'quantity' => $this->int($line, 'quantity'),
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
        ];
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
