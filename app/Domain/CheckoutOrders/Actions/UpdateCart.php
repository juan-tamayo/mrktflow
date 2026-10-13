<?php

declare(strict_types=1);

namespace App\Domain\CheckoutOrders\Actions;

use App\Domain\CheckoutOrders\Exceptions\CartConflict;
use App\Domain\CheckoutOrders\Exceptions\CartLineNotFound;
use App\Domain\CheckoutOrders\Support\CartCatalog;
use App\Domain\CheckoutOrders\Support\CartMoney;
use App\Domain\CheckoutOrders\Support\CartOwner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UpdateCart
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: ?string, revision: int, lines: list<array<string, mixed>>, subtotal: array{amount: int, currency: string, exponent: int, formatted: string}, notices: list<string>}
     */
    public function replaceLine(CartOwner $owner, array $input): array
    {
        $data = $this->validated($input);

        return DB::transaction(function () use ($owner, $data): array {
            $cart = $this->lockOrCreate($owner, $data['expected_revision']);
            $this->assertRevision($cart['revision'], $data['expected_revision']);
            $existing = $this->line($cart['id'], $data['variant_id']);
            if ($data['quantity'] === 0) {
                if ($existing === null) {
                    throw new CartLineNotFound;
                }
                DB::table('cart_lines')->where('id', $existing['id'])->delete();
            } else {
                $this->assertSellable($data['variant_id'], $data['quantity']);
                $now = now();
                if ($existing === null) {
                    DB::table('cart_lines')->insert([
                        'id' => (string) Str::uuid(),
                        'cart_id' => $cart['id'],
                        'variant_id' => $data['variant_id'],
                        'quantity' => $data['quantity'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('cart_lines')->where('id', $existing['id'])->update([
                        'quantity' => $data['quantity'],
                        'updated_at' => $now,
                    ]);
                }
            }

            return $this->bump($cart['id'], $cart['revision']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: ?string, revision: int, lines: list<array<string, mixed>>, subtotal: array{amount: int, currency: string, exponent: int, formatted: string}, notices: list<string>}
     */
    public function changeLine(CartOwner $owner, string $lineId, array $input): array
    {
        $quantity = $this->quantity($input);
        $expected = $this->revision($input);

        return DB::transaction(function () use ($owner, $lineId, $quantity, $expected): array {
            $cart = $this->lockOwned($owner);
            if ($cart === null) {
                throw new CartLineNotFound;
            }
            $this->assertRevision($cart['revision'], $expected);
            $existing = $this->lineById($cart['id'], $lineId);
            if ($existing === null) {
                throw new CartLineNotFound;
            }
            if ($quantity === 0) {
                DB::table('cart_lines')->where('id', $existing['id'])->delete();
            } else {
                $this->assertSellable($existing['variant_id'], $quantity);
                DB::table('cart_lines')->where('id', $existing['id'])->update([
                    'quantity' => $quantity,
                    'updated_at' => now(),
                ]);
            }

            return $this->bump($cart['id'], $cart['revision']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: ?string, revision: int, lines: list<array<string, mixed>>, subtotal: array{amount: int, currency: string, exponent: int, formatted: string}, notices: list<string>}
     */
    public function removeLine(CartOwner $owner, string $lineId, array $input): array
    {
        $input['quantity'] = 0;

        return $this->changeLine($owner, $lineId, $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{variant_id: string, quantity: int, expected_revision: int}
     */
    private function validated(array $input): array
    {
        $variantId = $input['variant_id'] ?? $input['variantId'] ?? null;
        if (! is_string($variantId) || ! Str::isUuid($variantId)) {
            throw ValidationException::withMessages(['variant_id' => 'Choose a product variant.']);
        }

        return [
            'variant_id' => $variantId,
            'quantity' => $this->quantity($input),
            'expected_revision' => $this->revision($input),
        ];
    }

    /** @param array<string, mixed> $input */
    private function quantity(array $input): int
    {
        $quantity = $input['quantity'] ?? null;
        if (! is_int($quantity) && ! (is_string($quantity) && preg_match('/^\d+$/', $quantity) === 1)) {
            throw ValidationException::withMessages(['quantity' => 'Enter a whole quantity.']);
        }
        $quantity = (int) $quantity;
        if ($quantity < 0 || $quantity > CartMoney::MAX_QUANTITY) {
            throw ValidationException::withMessages(['quantity' => 'That quantity is not allowed.']);
        }

        return $quantity;
    }

    /** @param array<string, mixed> $input */
    private function revision(array $input): int
    {
        $revision = $input['expected_revision'] ?? $input['expectedRevision'] ?? null;
        if (! is_int($revision) && ! (is_string($revision) && preg_match('/^\d+$/', $revision) === 1)) {
            throw ValidationException::withMessages(['expected_revision' => 'This cart changed. Reload before saving.']);
        }
        $revision = (int) $revision;
        if ($revision < 1) {
            throw new CartConflict;
        }

        return $revision;
    }

    private function assertSellable(string $variantId, int $quantity): void
    {
        $variant = app(CartCatalog::class)->variant($variantId);
        if ($variant === null || ! $variant['available']) {
            throw ValidationException::withMessages(['variant_id' => 'An item is no longer available.']);
        }
        if ($variant['type'] === 'digital' && $quantity !== 1) {
            throw ValidationException::withMessages(['quantity' => 'Digital items are limited to quantity one.']);
        }
        if ($quantity > $variant['max_quantity']) {
            throw ValidationException::withMessages(['quantity' => 'That quantity is not available.']);
        }
    }

    /**
     * @return array{id: string, revision: int}
     */
    private function lockOrCreate(CartOwner $owner, int $expectedRevision): array
    {
        $existing = $this->lockOwned($owner);
        if ($existing !== null) {
            return $existing;
        }
        if ($expectedRevision !== 1) {
            throw new CartConflict;
        }
        try {
            $now = now();
            DB::table('carts')->insert([
                'id' => (string) Str::uuid(),
                'customer_id' => $owner->customerId,
                'guest_token_hash' => $owner->guestTokenHash,
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = $this->lockOwned($owner);
            if ($existing === null) {
                throw new CartConflict;
            }

            return $existing;
        }
        $created = $this->lockOwned($owner);
        if ($created === null) {
            throw new CartConflict;
        }

        return $created;
    }

    /**
     * @return array{id: string, revision: int}|null
     */
    private function lockOwned(CartOwner $owner): ?array
    {
        $query = DB::table('carts');
        if ($owner->customerId !== null) {
            $query->where('customer_id', $owner->customerId);
        } else {
            $query->where('guest_token_hash', $owner->guestTokenHash);
        }

        return $this->cartRecord($query->lockForUpdate()->first());
    }

    /**
     * @return array{id: string, variant_id: string}|null
     */
    private function line(string $cartId, string $variantId): ?array
    {
        return $this->lineRecord(DB::table('cart_lines')->where('cart_id', $cartId)->where('variant_id', $variantId)->lockForUpdate()->first());
    }

    /**
     * @return array{id: string, variant_id: string}|null
     */
    private function lineById(string $cartId, string $lineId): ?array
    {
        return $this->lineRecord(DB::table('cart_lines')->where('cart_id', $cartId)->where('id', $lineId)->lockForUpdate()->first());
    }

    /**
     * @return array{id: string, revision: int}|null
     */
    private function cartRecord(mixed $row): ?array
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
     * @return array{id: string, variant_id: string}|null
     */
    private function lineRecord(mixed $row): ?array
    {
        if (! is_object($row)) {
            return null;
        }
        $data = get_object_vars($row);
        $id = $data['id'] ?? null;
        $variantId = $data['variant_id'] ?? null;
        if (! is_string($id) || ! is_string($variantId)) {
            return null;
        }

        return ['id' => $id, 'variant_id' => $variantId];
    }

    private function assertRevision(int $revision, int $expected): void
    {
        if ($revision !== $expected || $revision >= 4294967295) {
            throw new CartConflict;
        }
    }

    /**
     * @return array{id: ?string, revision: int, lines: list<array<string, mixed>>, subtotal: array{amount: int, currency: string, exponent: int, formatted: string}, notices: list<string>}
     */
    private function bump(string $cartId, int $revision): array
    {
        $next = $revision + 1;
        DB::table('carts')->where('id', $cartId)->update(['revision' => $next, 'updated_at' => now()]);

        return app(ReadCart::class)->hydrate($cartId, $next);
    }
}
