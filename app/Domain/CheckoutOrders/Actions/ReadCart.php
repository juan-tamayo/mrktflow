<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\Catalog\Support\StoreCurrency;
use App\Domain\CheckoutOrders\Support\CartCatalog;
use App\Domain\CheckoutOrders\Support\CartMoney;
use App\Domain\CheckoutOrders\Support\CartOwner;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReadCart
{
    /**
     * @return array{id: ?string, revision: int, lines: list<array<string, mixed>>, subtotal: array{amount: int, currency: string, exponent: int, formatted: string}, notices: list<string>}
     */
    public function snapshot(CartOwner $owner): array
    {
        $cart = $this->owned($owner);
        if ($cart === null) {
            return $this->emptySnapshot(null, 1);
        }

        return $this->hydrate($cart['id'], $cart['revision']);
    }

    /**
     * @return array{id: ?string, revision: int, lines: list<array<string, mixed>>, subtotal: array{amount: int, currency: string, exponent: int, formatted: string}, notices: list<string>}
     */
    public function hydrate(string $cartId, int $revision): array
    {
        $catalog = app(CartCatalog::class);
        $lines = [];
        $notices = [];
        $subtotal = 0;
        $currency = '';
        $exponent = 0;
        $priced = false;
        $rows = DB::table('cart_lines')->where('cart_id', $cartId)->orderBy('created_at')->orderBy('id')->get();
        foreach ($rows as $row) {
            $line = get_object_vars($row);
            $variantId = is_string($line['variant_id'] ?? null) ? $line['variant_id'] : '';
            $variant = $catalog->variant($variantId);
            if ($variant === null) {
                throw new InvalidArgumentException('Cart line variant is missing.');
            }
            $quantity = (int) ($line['quantity'] ?? 0);
            $lineTotal = CartMoney::times($variant['price_amount'], $quantity);
            if (! $priced) {
                $currency = $variant['currency'];
                $exponent = $variant['exponent'];
                $priced = true;
            }
            if ($variant['currency'] !== $currency || $variant['exponent'] !== $exponent) {
                throw new InvalidArgumentException('Cart currencies must match.');
            }
            if ($subtotal > CartMoney::MAX_SAFE - $lineTotal) {
                throw new InvalidArgumentException('Unsupported total.');
            }
            $subtotal += $lineTotal;
            if (! $variant['available']) {
                $notices[] = 'An item is no longer available.';
            }
            $lineId = is_string($line['id'] ?? null) ? $line['id'] : '';
            $lines[] = [
                'id' => $lineId,
                'variant_id' => $variant['variant_id'],
                'product_id' => $variant['product_id'],
                'title' => $variant['title'],
                'sku' => $variant['sku'],
                'type' => $variant['type'],
                'quantity' => $quantity,
                'available' => $variant['available'],
                'availability' => $variant['availability'],
                'max_quantity' => $variant['max_quantity'],
                'money' => CartMoney::format($variant['price_amount'], $variant['currency'], $variant['exponent']),
                'line_total' => CartMoney::format($lineTotal, $variant['currency'], $variant['exponent']),
            ];
        }
        $notices = array_values(array_unique($notices));
        if (! $priced) {
            return $this->emptySnapshot($cartId, $revision);
        }

        return [
            'id' => $cartId,
            'revision' => $revision,
            'lines' => $lines,
            'subtotal' => CartMoney::format($subtotal, $currency, $exponent),
            'notices' => $notices,
        ];
    }

    /**
     * @return array{id: string, revision: int}|null
     */
    public function owned(CartOwner $owner): ?array
    {
        $query = DB::table('carts');
        if ($owner->customerId !== null) {
            $query->where('customer_id', $owner->customerId);
        } else {
            $query->where('guest_token_hash', $owner->guestTokenHash);
        }
        $row = $query->first();
        if (! is_object($row)) {
            return null;
        }
        $data = get_object_vars($row);
        $id = $data['id'] ?? null;
        $revision = $data['revision'] ?? null;
        if (! is_string($id) || (! is_int($revision) && ! is_numeric($revision))) {
            return null;
        }

        return ['id' => $id, 'revision' => (int) $revision];
    }

    /**
     * @return array{id: ?string, revision: int, lines: list<array<string, mixed>>, subtotal: array{amount: int, currency: string, exponent: int, formatted: string}, notices: list<string>}
     */
    private function emptySnapshot(?string $id, int $revision): array
    {
        $money = app(StoreCurrency::class)->current();

        return [
            'id' => $id,
            'revision' => $revision,
            'lines' => [],
            'subtotal' => CartMoney::format(0, $money['currency'], $money['exponent']),
            'notices' => [],
        ];
    }
}
