<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\CheckoutOrders\Support\CartCatalog;
use App\Domain\CheckoutOrders\Support\CartMoney;
use App\Domain\CheckoutOrders\Support\CartOwner;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MergeCart
{
    /**
     * @return array{id: ?string, revision: int, lines: list<array<string, mixed>>, subtotal: array{amount: int, currency: string, exponent: int, formatted: string}, notices: list<string>}
     */
    public function merge(CartOwner $guest, CartOwner $customer): array
    {
        if ($guest->customerId !== null || $guest->guestTokenHash === null
            || $customer->customerId === null || $customer->guestTokenHash !== null) {
            throw new InvalidArgumentException('Cart merge requires a guest cart and a customer cart.');
        }

        return DB::transaction(function () use ($guest, $customer): array {
            $rows = DB::table('carts')
                ->where(function (Builder $query) use ($guest, $customer): void {
                    $query->where('guest_token_hash', $guest->guestTokenHash)
                        ->orWhere('customer_id', $customer->customerId);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $guestCart = null;
            $customerCart = null;
            foreach ($rows as $row) {
                $record = $this->cart($row);
                if ($record === null) {
                    continue;
                }
                if (($row->guest_token_hash ?? null) === $guest->guestTokenHash) {
                    $guestCart = $record;
                }
                if (($row->customer_id ?? null) === $customer->customerId) {
                    $customerCart = $record;
                }
            }
            if ($guestCart === null) {
                return $this->unchanged();
            }
            $guestLineCount = (int) DB::table('cart_lines')->where('cart_id', $guestCart['id'])->lockForUpdate()->count();
            if ($guestLineCount === 0) {
                DB::table('carts')->where('id', $guestCart['id'])->delete();

                return $this->unchanged();
            }
            if ($customerCart === null) {
                DB::table('carts')->where('id', $guestCart['id'])->update([
                    'customer_id' => $customer->customerId,
                    'guest_token_hash' => null,
                    'revision' => $guestCart['revision'] + 1,
                    'updated_at' => now(),
                ]);

                return app(ReadCart::class)->hydrate($guestCart['id'], $guestCart['revision'] + 1);
            }

            $adjusted = false;
            $guestLines = DB::table('cart_lines')->where('cart_id', $guestCart['id'])->orderBy('id')->lockForUpdate()->get();
            $accountLines = DB::table('cart_lines')->where('cart_id', $customerCart['id'])->orderBy('id')->lockForUpdate()->get();
            $accountByVariant = [];
            foreach ($accountLines as $line) {
                $accountByVariant[(string) $line->variant_id] = $line;
            }
            foreach ($guestLines as $line) {
                $variantId = (string) $line->variant_id;
                $guestQty = (int) $line->quantity;
                $existing = $accountByVariant[$variantId] ?? null;
                $accountQty = $existing !== null ? (int) $existing->quantity : 0;
                [$quantity, $lineAdjusted] = $this->quantity($variantId, $guestQty, $accountQty);
                $adjusted = $adjusted || $lineAdjusted;
                if ($existing !== null) {
                    DB::table('cart_lines')->where('id', $existing->id)->update([
                        'quantity' => $quantity,
                        'updated_at' => now(),
                    ]);
                    DB::table('cart_lines')->where('id', $line->id)->delete();
                    $existing->quantity = $quantity;
                } else {
                    DB::table('cart_lines')->where('id', $line->id)->update([
                        'cart_id' => $customerCart['id'],
                        'quantity' => $quantity,
                        'updated_at' => now(),
                    ]);
                    $line->cart_id = $customerCart['id'];
                    $line->quantity = $quantity;
                    $accountByVariant[$variantId] = $line;
                }
            }
            DB::table('carts')->where('id', $guestCart['id'])->delete();
            $revision = $customerCart['revision'] + 1;
            DB::table('carts')->where('id', $customerCart['id'])->update([
                'revision' => $revision,
                'updated_at' => now(),
            ]);
            $snapshot = app(ReadCart::class)->hydrate($customerCart['id'], $revision);
            if ($adjusted) {
                $snapshot['notices'] = array_values(array_unique(array_merge(
                    ['Some quantities were adjusted to the allowed maximum.'],
                    $snapshot['notices'],
                )));
            }

            return $snapshot;
        });
    }

    /**
     * @return array{id: ?string, revision: int, lines: list<array<string, mixed>>, subtotal: array{amount: int, currency: string, exponent: int, formatted: string}, notices: list<string>}
     */
    private function unchanged(): array
    {
        return [
            'id' => null,
            'revision' => 1,
            'lines' => [],
            'subtotal' => ['amount' => 0, 'currency' => '', 'exponent' => 0, 'formatted' => ''],
            'notices' => [],
        ];
    }

    /**
     * @return array{id: string, revision: int}|null
     */
    private function cart(mixed $row): ?array
    {
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
     * @return array{0: int, 1: bool}
     */
    private function quantity(string $variantId, int $guestQty, int $accountQty): array
    {
        $variant = app(CartCatalog::class)->variant($variantId);
        $type = is_array($variant) ? $variant['type'] : 'physical';
        if ($type === 'digital') {
            return [1, $accountQty > 0 || $guestQty !== 1];
        }
        $sum = $guestQty + $accountQty;
        $max = CartMoney::MAX_QUANTITY;
        if (is_array($variant) && $variant['max_quantity'] > 0) {
            $max = min($max, $variant['max_quantity']);
        }
        $quantity = min($sum, $max);
        if ($quantity < 1) {
            $quantity = min(max($sum, 1), CartMoney::MAX_QUANTITY);
        }

        return [$quantity, $quantity !== $sum];
    }
}
