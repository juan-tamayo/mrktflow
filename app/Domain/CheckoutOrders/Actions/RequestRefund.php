<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\CheckoutOrders\Contracts\PaymentGateway;
use App\Domain\CheckoutOrders\Data\PaymentIntent;
use App\Domain\CheckoutOrders\Data\RefundIntent;
use App\Domain\CheckoutOrders\Exceptions\CheckoutIdempotencyConflict;
use App\Domain\CheckoutOrders\Support\RefundAllocator;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RequestRefund
{
    public const OPERATION = 'order-refund';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function request(StaffAccount $actor, string $orderId, array $input, string $idempotencyKey): array
    {
        if (! $actor->hasPermission('orders.refund')) {
            throw new AuthorizationException;
        }
        if (! Str::isUuid($orderId) || $orderId !== strtolower($orderId)
            || ! Str::isUuid($idempotencyKey) || $idempotencyKey !== strtolower($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Send a stable refund key.']);
        }
        $prepared = $this->claim($actor, $orderId, $input, $idempotencyKey);
        if ($prepared['replay']) {
            return $prepared['view'];
        }
        $result = app(PaymentGateway::class)->refund($prepared['intent']);
        app(ApplyRefundOutcome::class)->apply($prepared['refund_id'], $result);

        return $this->view($prepared['refund_id']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{replay: true, view: array<string, mixed>}|array{replay: false, refund_id: string, intent: RefundIntent}
     */
    private function claim(StaffAccount $actor, string $orderId, array $input, string $key): array
    {
        return DB::transaction(function () use ($actor, $orderId, $input, $key): array {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new NotFoundHttpException;
            }
            $normalized = $this->normalized($input);
            $hash = hash('sha256', json_encode([
                'order_id' => $orderId,
                'kind' => $normalized['kind'],
                'amount' => $normalized['amount'],
                'shipping_amount' => $normalized['shipping_amount'],
                'lines' => $normalized['lines'],
                'reason' => $normalized['reason'],
            ], JSON_THROW_ON_ERROR));
            $claim = $this->idempotency($actor, $key, $hash);
            if ($claim['replay']) {
                $refundId = $this->nullableObject($claim, 'resource_id');
                if ($refundId === null) {
                    throw new CheckoutIdempotencyConflict;
                }

                return ['replay' => true, 'view' => $this->view($refundId)];
            }
            if ($this->bool($order, 'zero_total') || ! in_array($this->string($order, 'status'), ['confirmed', 'on_hold', 'completed'], true)) {
                throw ValidationException::withMessages(['order' => 'This order has no captured payment to refund.']);
            }
            $payment = DB::table('payments')->where('order_id', $orderId)->lockForUpdate()->first();
            $attempt = $payment === null ? null : DB::table('payment_attempts')->where('id', $this->string($payment, 'attempt_id'))->lockForUpdate()->first();
            if ($payment === null || $attempt === null || $this->string($payment, 'provider') !== 'stripe') {
                throw ValidationException::withMessages(['order' => 'This order has no captured payment to refund.']);
            }
            $lines = array_values(DB::table('order_lines')->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get()->all());
            $allocated = $this->allocate($order, $payment, $lines, $normalized);
            $refundId = (string) Str::uuid();
            $now = now();
            DB::table('refunds')->insert([
                'id' => $refundId,
                'order_id' => $orderId,
                'payment_id' => $this->string($payment, 'id'),
                'staff_id' => $actor->id,
                'amount' => $allocated['amount'],
                'shipping_amount' => $allocated['shipping_amount'],
                'currency' => $this->string($order, 'currency'),
                'exponent' => $this->int($order, 'exponent'),
                'kind' => $allocated['kind'],
                'status' => 'processing',
                'reason' => $normalized['reason'],
                'capture_id' => $this->string($payment, 'provider_capture_id'),
                'provider_refund_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($allocated['lines'] as $line) {
                DB::table('refund_lines')->insert([
                    'id' => (string) Str::uuid(),
                    'refund_id' => $refundId,
                    'order_line_id' => $line['id'],
                    'quantity' => $line['quantity'],
                    'amount' => $line['amount'],
                ]);
                DB::table('order_lines')->where('id', $line['id'])->increment('held_qty', $line['quantity']);
            }
            DB::table('idempotency_records')->where('id', $claim['id'])->update([
                'status' => 'completed',
                'resource_id' => $refundId,
                'updated_at' => $now,
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:order-refund-request:'.$refundId, [
                'action' => 'checkout.order.refund_requested',
                'subject_type' => 'order',
                'subject_id' => $orderId,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['version' => 1, 'kind' => $allocated['kind']],
            ]);
            $account = $this->nullable($attempt, 'provider_account');
            if ($account === null) {
                throw ValidationException::withMessages(['order' => 'This order has no captured payment to refund.']);
            }
            try {
                $intent = new RefundIntent(
                    $refundId,
                    new PaymentIntent(
                        $this->string($attempt, 'id'),
                        $this->int($payment, 'amount'),
                        $this->string($payment, 'currency'),
                        $account,
                        $this->bool($attempt, 'live'),
                    ),
                    $this->string($payment, 'provider_capture_id'),
                    $allocated['amount'],
                );
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['amount' => 'This refund amount is not refundable.']);
            }

            return ['replay' => false, 'refund_id' => $refundId, 'intent' => $intent];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{kind: string, amount: int, shipping_amount: int, reason: string, lines: list<array{id: string, quantity: int}>}
     */
    private function normalized(array $input): array
    {
        $reason = is_string($input['reason'] ?? null) ? trim((string) $input['reason']) : '';
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Enter a refund reason of at most 500 characters.']);
        }
        $shipping = $input['shipping_amount'] ?? 0;
        if (! is_int($shipping) && ! (is_string($shipping) && preg_match('/^\d+$/', $shipping) === 1)) {
            throw ValidationException::withMessages(['shipping_amount' => 'Shipping refund must be a whole amount.']);
        }
        $shipping = (int) $shipping;
        $amount = $input['amount'] ?? 0;
        if (! is_int($amount) && ! (is_string($amount) && preg_match('/^\d+$/', $amount) === 1)) {
            throw ValidationException::withMessages(['amount' => 'Refund amount must be a whole amount.']);
        }
        $amount = (int) $amount;
        $lines = [];
        $posted = $input['lines'] ?? [];
        if (! is_array($posted)) {
            throw ValidationException::withMessages(['lines' => 'Choose refund quantities from order lines.']);
        }
        foreach ($posted as $line) {
            if (! is_array($line) || ! is_string($line['id'] ?? null) || ! Str::isUuid((string) $line['id'])) {
                continue;
            }
            $quantity = $line['quantity'] ?? 0;
            if (! is_int($quantity) && ! (is_string($quantity) && preg_match('/^\d+$/', $quantity) === 1)) {
                throw ValidationException::withMessages(['lines' => 'Refund quantities must be whole numbers.']);
            }
            $quantity = (int) $quantity;
            if ($quantity < 1) {
                continue;
            }
            $id = strtolower((string) $line['id']);
            $lines[] = ['id' => $id, 'quantity' => $quantity];
        }
        usort($lines, fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        $kind = $lines === [] ? 'goodwill' : 'quantity';
        if ($kind === 'goodwill' && $amount < 1) {
            throw ValidationException::withMessages(['amount' => 'Enter a goodwill amount or unshipped quantities.']);
        }
        if ($kind === 'quantity' && $amount > 0) {
            throw ValidationException::withMessages(['amount' => 'Quantity refunds compute money from remaining units.']);
        }

        return [
            'kind' => $kind,
            'amount' => $kind === 'goodwill' ? $amount : 0,
            'shipping_amount' => $shipping,
            'reason' => $reason,
            'lines' => $lines,
        ];
    }

    /**
     * @param  list<object>  $lines
     * @param  array{kind: string, amount: int, shipping_amount: int, reason: string, lines: list<array{id: string, quantity: int}>}  $request
     * @return array{kind: string, amount: int, shipping_amount: int, lines: list<array{id: string, quantity: int, amount: int}>}
     */
    private function allocate(object $order, object $payment, array $lines, array $request): array
    {
        $allocator = new RefundAllocator;
        $byId = [];
        foreach ($lines as $line) {
            $byId[$this->string($line, 'id')] = $line;
        }
        $open = $this->openAllocations($this->string($order, 'id'));
        $captured = $this->int($payment, 'amount');
        $remainingMoney = $captured - $open['amount'];
        if ($remainingMoney < 1) {
            throw ValidationException::withMessages(['amount' => 'No remaining captured money is available to refund.']);
        }
        $shippingTax = $this->int($order, 'tax_amount');
        foreach ($lines as $line) {
            $shippingTax -= $this->int($line, 'tax_amount');
        }
        $remainingShipping = $this->int($order, 'shipping_amount') + max(0, $shippingTax) - $open['shipping'];
        $claimedLines = [];
        $merchandise = 0;
        if ($request['kind'] === 'quantity') {
            foreach ($request['lines'] as $item) {
                $line = $byId[$item['id']] ?? null;
                if ($line === null) {
                    throw ValidationException::withMessages(['lines' => 'A refund line is missing from this order.']);
                }
                $available = $this->int($line, 'quantity') - $this->int($line, 'shipped_qty')
                    - $this->int($line, 'cancelled_qty') - $this->int($line, 'held_qty');
                if ($item['quantity'] > $available) {
                    throw ValidationException::withMessages(['lines' => 'Refund quantities must stay within remaining unshipped units.']);
                }
                $consumed = $this->int($line, 'cancelled_qty') + $this->int($line, 'held_qty');
                $lineAmount = $allocator->slice($allocator->unitAmounts($this->int($line, 'total_amount'), $this->int($line, 'quantity')), $consumed, $item['quantity'])
                    + $allocator->slice($allocator->unitAmounts($this->int($line, 'tax_amount'), $this->int($line, 'quantity')), $consumed, $item['quantity']);
                $claimedLines[] = ['id' => $item['id'], 'quantity' => $item['quantity'], 'amount' => $lineAmount];
                $merchandise += $lineAmount;
            }
            $shipping = $request['shipping_amount'];
            if ($shipping > $remainingShipping) {
                throw ValidationException::withMessages(['shipping_amount' => 'Shipping refunds must stay within remaining shipping.']);
            }
            $total = $merchandise + $shipping;
            if ($total < 1 || $total > $remainingMoney) {
                throw ValidationException::withMessages(['amount' => 'Refund allocation exceeds remaining captured money.']);
            }

            return ['kind' => 'quantity', 'amount' => $total, 'shipping_amount' => $shipping, 'lines' => $claimedLines];
        }
        $amount = $request['amount'];
        if ($request['shipping_amount'] > 0 || $amount > $remainingMoney) {
            throw ValidationException::withMessages(['amount' => 'Goodwill refunds must stay within remaining captured money.']);
        }

        return ['kind' => 'goodwill', 'amount' => $amount, 'shipping_amount' => 0, 'lines' => []];
    }

    /** @return array{amount: int, shipping: int} */
    private function openAllocations(string $orderId): array
    {
        $rows = DB::table('refunds')->where('order_id', $orderId)
            ->whereIn('status', ['processing', 'unknown', 'succeeded'])
            ->lockForUpdate()
            ->get();
        $amount = 0;
        $shipping = 0;
        foreach ($rows as $row) {
            $amount += $this->int($row, 'amount');
            $shipping += $this->int($row, 'shipping_amount');
        }

        return ['amount' => $amount, 'shipping' => $shipping];
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
                throw new CheckoutIdempotencyConflict;
            }
            if ($this->string($existing, 'payload_hash') !== $hash) {
                throw new CheckoutIdempotencyConflict;
            }

            return [
                'id' => $this->string($existing, 'id'),
                'replay' => true,
                'resource_id' => $this->nullable($existing, 'resource_id'),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function view(string $refundId): array
    {
        $refund = DB::table('refunds')->where('id', $refundId)->first();
        if ($refund === null) {
            throw new NotFoundHttpException;
        }

        return [
            'id' => $this->string($refund, 'id'),
            'order_id' => $this->string($refund, 'order_id'),
            'status' => $this->string($refund, 'status'),
            'kind' => $this->string($refund, 'kind'),
            'amount' => $this->int($refund, 'amount'),
            'currency' => $this->string($refund, 'currency'),
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

    /** @param  array{resource_id: ?string}  $claim */
    private function nullableObject(array $claim, string $key): ?string
    {
        $value = $claim[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function int(object $row, string $key): int
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function bool(object $row, string $key): bool
    {
        $value = get_object_vars($row)[$key] ?? null;

        return $value === true || $value === 1 || $value === '1';
    }
}
