<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\CheckoutOrders\Support\CartMoney;
use App\Domain\Identity\Models\CustomerAccount;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReadCustomerOrders
{
    /**
     * @return array{data: list<array<string, mixed>>}
     */
    public function list(CustomerAccount $actor): array
    {
        $data = [];
        foreach (DB::table('orders')->where('customer_id', $actor->id)->orderByDesc('created_at')->orderByDesc('id')->get() as $row) {
            $currency = $this->string($row, 'currency');
            $exponent = $this->int($row, 'exponent');
            $data[] = [
                'id' => $this->string($row, 'id'),
                'number' => $this->string($row, 'number'),
                'email' => $this->string($row, 'email'),
                'status' => $this->string($row, 'status'),
                'financial_status' => $this->string($row, 'financial_status'),
                'created_at' => $this->iso($this->string($row, 'created_at')),
                'grand_total' => CartMoney::format($this->int($row, 'grand_total_amount'), $currency, $exponent),
            ];
        }

        return ['data' => $data];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(CustomerAccount $actor, string $orderId): array
    {
        $order = DB::table('orders')->where('id', $orderId)->where('customer_id', $actor->id)->first();
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
                'total' => CartMoney::format($this->int($line, 'total_amount'), $currency, $exponent),
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
            'totals' => [
                'merchandise' => CartMoney::format($this->int($order, 'merchandise_amount'), $currency, $exponent),
                'discount' => CartMoney::format($this->int($order, 'discount_amount'), $currency, $exponent),
                'shipping' => CartMoney::format($this->int($order, 'shipping_amount'), $currency, $exponent),
                'tax' => CartMoney::format($this->int($order, 'tax_amount'), $currency, $exponent),
                'grand_total' => CartMoney::format($this->int($order, 'grand_total_amount'), $currency, $exponent),
            ],
            'lines' => $lines,
            'receipt' => [
                'template' => 'order-receipt',
                'grand_total' => CartMoney::format($this->int($order, 'grand_total_amount'), $currency, $exponent),
            ],
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

    private function iso(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s\\Z');
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
