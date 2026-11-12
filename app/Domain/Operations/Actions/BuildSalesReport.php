<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\CheckoutOrders\Support\CartMoney;
use App\Domain\CheckoutOrders\Support\RefundAllocator;
use App\Domain\Identity\Models\StaffAccount;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class BuildSalesReport
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(StaffAccount $actor, array $filters): array
    {
        if (! $actor->hasPermission('reports.read')) {
            throw new AuthorizationException;
        }
        $timezone = $this->storeTimezone();
        $currency = $this->storeCurrency();
        $from = $this->day($filters['from'] ?? null, $timezone)
            ?? $this->day($filters['to'] ?? null, $timezone)
            ?? $this->today($timezone);
        $to = $this->day($filters['to'] ?? null, $timezone) ?? $from;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        [$startUtc, $endUtc] = $this->utcBounds($timezone, $from, $to);
        $captures = $this->captures($startUtc, $endUtc);
        $activityRefunds = $this->merchandiseRefunds($this->refundIds($startUtc, $endUtc, false));
        $cohortRefunds = $this->merchandiseRefunds($this->refundIds($startUtc, $endUtc, true));
        $activity = $this->totals($captures, $activityRefunds, $currency);
        $cohort = $this->totals($captures, $cohortRefunds, $currency);

        return [
            'timezone' => $timezone,
            'from' => $from,
            'to' => $to,
            'currency' => $currency,
            'exponent' => 2,
            'rules' => [
                'include_paid' => true,
                'include_test' => false,
                'include_unpaid' => false,
                'date_basis' => 'store_timezone_calendar_days',
                'capture_date' => 'payments.captured_at',
                'refund_date' => 'refund_succeeded_at',
                'cohort_refunds' => 'original_capture_date',
            ],
            'definitions' => [
                'paid_orders' => 'Live captured payments and local zero-total completions. Stripe test-mode attempts and unpaid or unknown orders are excluded.',
                'gross' => 'Sum of frozen merchandise_amount on included captures in the store-timezone range.',
                'discounts' => 'Sum of frozen merchandise discount_amount on those captures.',
                'refunds' => 'Succeeded merchandise refunds (quantity line totals excluding line tax, plus goodwill amounts). Activity uses the succeeded refund timestamp; cohort attributes refunds to the original capture date.',
                'net' => 'Gross merchandise minus discounts minus merchandise refunds. Activity net can be negative on a refund-only day.',
                'tax' => 'Sum of frozen tax_amount collected on included captures. Refunded tax is not subtracted here.',
                'shipping' => 'Sum of frozen shipping_amount collected on included captures. Refunded shipping is not subtracted here.',
                'aov' => 'Round-half-up of net merchandise divided by paid order count; zero when the window has no paid orders.',
            ],
            'activity' => $activity,
            'cohort' => $cohort,
        ];
    }

    /**
     * @param  array{paid_orders: int, gross: int, discounts: int, tax: int, shipping: int}  $captures
     * @return array<string, mixed>
     */
    private function totals(array $captures, int $refunds, string $currency): array
    {
        $net = $captures['gross'] - $captures['discounts'] - $refunds;

        return [
            'paid_orders' => $captures['paid_orders'],
            'gross' => $this->money($captures['gross'], $currency),
            'discounts' => $this->money($captures['discounts'], $currency),
            'refunds' => $this->money($refunds, $currency),
            'net' => $this->money($net, $currency),
            'tax' => $this->money($captures['tax'], $currency),
            'shipping' => $this->money($captures['shipping'], $currency),
            'aov' => $this->money($this->average($net, $captures['paid_orders']), $currency),
        ];
    }

    /**
     * @return array{paid_orders: int, gross: int, discounts: int, tax: int, shipping: int}
     */
    private function captures(string $startUtc, string $endUtc): array
    {
        $row = $this->eligibleOrders()
            ->where('payments.captured_at', '>=', $startUtc)
            ->where('payments.captured_at', '<', $endUtc)
            ->selectRaw('COUNT(*) AS paid_orders')
            ->selectRaw('COALESCE(SUM(orders.merchandise_amount), 0) AS gross')
            ->selectRaw('COALESCE(SUM(orders.discount_amount), 0) AS discounts')
            ->selectRaw('COALESCE(SUM(orders.tax_amount), 0) AS tax')
            ->selectRaw('COALESCE(SUM(orders.shipping_amount), 0) AS shipping')
            ->first();

        return [
            'paid_orders' => $this->int($row, 'paid_orders'),
            'gross' => $this->int($row, 'gross'),
            'discounts' => $this->int($row, 'discounts'),
            'tax' => $this->int($row, 'tax'),
            'shipping' => $this->int($row, 'shipping'),
        ];
    }

    /** @return list<string> */
    private function refundIds(string $startUtc, string $endUtc, bool $cohort): array
    {
        $query = DB::table('refunds')
            ->join('orders', 'orders.id', '=', 'refunds.order_id')
            ->join('payments', 'payments.id', '=', 'refunds.payment_id')
            ->join('payment_attempts', 'payment_attempts.id', '=', 'payments.attempt_id')
            ->where('refunds.status', 'succeeded')
            ->whereIn('orders.financial_status', ['paid', 'partially_refunded', 'refunded'])
            ->where(function (Builder $eligible): void {
                $this->liveOrLocal($eligible);
            });
        if ($cohort) {
            $query->where('payments.captured_at', '>=', $startUtc)
                ->where('payments.captured_at', '<', $endUtc);
        } else {
            $query->where('refunds.updated_at', '>=', $startUtc)
                ->where('refunds.updated_at', '<', $endUtc);
        }
        $ids = [];
        foreach ($query->select('refunds.id as refund_id')->orderBy('refunds.id')->pluck('refund_id') as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @param  list<string>  $refundIds */
    private function merchandiseRefunds(array $refundIds): int
    {
        if ($refundIds === []) {
            return 0;
        }
        $refunds = DB::table('refunds')->whereIn('id', $refundIds)->orderBy('created_at')->orderBy('id')->get();
        $total = 0;
        $cursors = [];
        $allocator = new RefundAllocator;
        foreach ($refunds as $refund) {
            $kind = $this->string($refund, 'kind');
            if ($kind === 'goodwill') {
                $total += $this->int($refund, 'amount');

                continue;
            }
            $lines = DB::table('refund_lines')
                ->where('refund_id', $this->string($refund, 'id'))
                ->orderBy('order_line_id')
                ->get();
            foreach ($lines as $claimed) {
                $line = DB::table('order_lines')->where('id', $this->string($claimed, 'order_line_id'))->first();
                if ($line === null) {
                    continue;
                }
                $lineId = $this->string($line, 'id');
                $quantity = $this->int($claimed, 'quantity');
                $cursor = $cursors[$lineId] ?? 0;
                $units = $allocator->unitAmounts($this->int($line, 'total_amount'), $this->int($line, 'quantity'));
                $total += $allocator->slice($units, $cursor, $quantity);
                $cursors[$lineId] = $cursor + $quantity;
            }
        }

        return $total;
    }

    private function eligibleOrders(): Builder
    {
        return DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->join('payment_attempts', 'payment_attempts.id', '=', 'payments.attempt_id')
            ->whereIn('orders.financial_status', ['paid', 'partially_refunded', 'refunded'])
            ->where(function (Builder $eligible): void {
                $this->liveOrLocal($eligible);
            });
    }

    private function liveOrLocal(Builder $eligible): void
    {
        $eligible->where(function (Builder $stripe): void {
            $stripe->where('payments.provider', 'stripe')->where('payment_attempts.live', true);
        })->orWhere(function (Builder $local): void {
            $local->where('payments.provider', 'local')->where('orders.zero_total', true);
        });
    }

    /** @return array{0: string, 1: string} */
    private function utcBounds(string $timezone, string $from, string $to): array
    {
        $tz = new DateTimeZone($timezone);
        $utc = new DateTimeZone('UTC');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
        if (! $start instanceof DateTimeImmutable || ! $end instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('A store-timezone date range is required.');
        }

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    private function day(mixed $value, string $timezone): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new DateTimeZone($timezone));

        return $date instanceof DateTimeImmutable ? $date->format('Y-m-d') : null;
    }

    private function today(string $timezone): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
    }

    private function storeTimezone(): string
    {
        $settings = $this->settings();
        $timezone = $settings['timezone'] ?? null;
        if (! is_string($timezone) || $timezone === '' || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return 'UTC';
        }

        return $timezone;
    }

    private function storeCurrency(): string
    {
        $settings = $this->settings();
        $currency = $settings['currency'] ?? null;

        return is_string($currency) && $currency !== '' ? $currency : 'USD';
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $raw = DB::table('store_settings')->where('id', 1)->value('values');
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{amount: int, currency: string, exponent: int, formatted: string}
     */
    private function money(int $amount, string $currency): array
    {
        $sign = $amount < 0 ? '-' : '';
        $formatted = CartMoney::format(abs($amount), $currency, 2);
        $formatted['amount'] = $amount;
        if ($sign !== '') {
            $formatted['formatted'] = $sign.$formatted['formatted'];
        }

        return $formatted;
    }

    private function average(int $net, int $orders): int
    {
        if ($orders < 1) {
            return 0;
        }
        $quotient = bcdiv((string) $net, (string) $orders, 8);
        $negative = bccomp($quotient, '0', 8) < 0;
        $abs = $negative ? bcmul($quotient, '-1', 8) : $quotient;
        $whole = (int) bcdiv($abs, '1', 0);
        $fraction = bcsub($abs, (string) $whole, 8);
        if (bccomp($fraction, '0.5', 8) >= 0) {
            $whole++;
        }

        return $negative ? -$whole : $whole;
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function int(?object $row, string $key): int
    {
        if ($row === null) {
            return 0;
        }
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
