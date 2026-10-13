<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdjustStock
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{variant_id: string, tracks_inventory: bool, on_hand: int|null, reserved: int, available_quantity: int|null, low_stock_threshold: int|null, low_stock: bool}
     */
    public function adjust(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('catalog.write')) {
            throw new AuthorizationException;
        }
        $data = Validator::make($input, [
            'product_id' => ['sometimes', 'uuid'],
            'variant_id' => ['required', 'uuid'],
            'operation_key' => ['required', 'uuid'],
            'tracks_inventory' => ['required', 'boolean'],
            'on_hand' => ['required_if:tracks_inventory,true', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ])->validate();
        $data['tracks_inventory'] = (bool) $data['tracks_inventory'];
        $data['on_hand'] = array_key_exists('on_hand', $data) && $data['on_hand'] !== null ? (int) $data['on_hand'] : null;
        $data['low_stock_threshold'] = array_key_exists('low_stock_threshold', $data) && $data['low_stock_threshold'] !== null
            ? (int) $data['low_stock_threshold'] : null;

        return DB::transaction(function () use ($actor, $data): array {
            $variant = DB::table('product_variants')->where('id', $data['variant_id'])->lockForUpdate()->first();
            abort_unless($variant !== null, 404);
            if (isset($data['product_id']) && $variant->product_id !== $data['product_id']) {
                abort(404);
            }
            $productType = DB::table('products')->where('id', $variant->product_id)->lockForUpdate()->value('type');
            if ($productType === 'digital' && $data['tracks_inventory']) {
                throw ValidationException::withMessages(['tracks_inventory' => 'Digital variants are untracked.']);
            }
            $balance = DB::table('inventory_balances')->where('variant_id', $data['variant_id'])->lockForUpdate()->first();
            if ($data['tracks_inventory']) {
                $onHand = $data['on_hand'] ?? 0;
                $reserved = $balance !== null ? (int) $balance->reserved : 0;
                if ($onHand < $reserved) {
                    throw ValidationException::withMessages(['on_hand' => 'On-hand stock cannot be lower than reserved units.']);
                }
            } elseif ($balance !== null && (int) $balance->reserved > 0) {
                throw ValidationException::withMessages(['tracks_inventory' => 'Reserved units must be released before turning tracking off.']);
            }
            $hash = hash('sha256', json_encode([
                'action' => 'adjust',
                'variant_id' => $data['variant_id'],
                'tracks_inventory' => $data['tracks_inventory'],
                'on_hand' => $data['on_hand'],
                'low_stock_threshold' => $data['low_stock_threshold'],
            ], JSON_THROW_ON_ERROR));
            try {
                DB::table('inventory_operations')->insert([
                    'operation_key' => $data['operation_key'],
                    'intent_id' => $data['variant_id'],
                    'action' => 'adjust',
                    'payload_hash' => $hash,
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $existing = DB::table('inventory_operations')->where('operation_key', $data['operation_key'])->lockForUpdate()->first();
                if ($existing === null || $existing->payload_hash !== $hash || $existing->action !== 'adjust') {
                    throw ValidationException::withMessages(['operation_key' => 'This stock adjustment conflicts with a recorded request.']);
                }

                return $this->snapshot($data['variant_id']);
            }
            $now = now();
            if ($data['tracks_inventory']) {
                $onHand = $data['on_hand'] ?? 0;
                DB::table('product_variants')->where('id', $data['variant_id'])->update([
                    'tracks_inventory' => true, 'updated_at' => $now,
                ]);
                if ($balance === null) {
                    DB::table('inventory_balances')->insert([
                        'id' => (string) Str::uuid(),
                        'variant_id' => $data['variant_id'],
                        'on_hand' => $onHand,
                        'reserved' => 0,
                        'low_stock_threshold' => $data['low_stock_threshold'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $delta = $onHand;
                } else {
                    $delta = $onHand - (int) $balance->on_hand;
                    DB::table('inventory_balances')->where('variant_id', $data['variant_id'])->update([
                        'on_hand' => $onHand,
                        'low_stock_threshold' => $data['low_stock_threshold'],
                        'updated_at' => $now,
                    ]);
                }
                DB::table('stock_movements')->insert([
                    'id' => (string) Str::uuid(),
                    'source_ref' => 'adjust:'.$data['operation_key'].':'.$data['variant_id'],
                    'variant_id' => $data['variant_id'],
                    'reservation_id' => null,
                    'on_hand_delta' => $delta,
                    'reserved_delta' => 0,
                    'reason' => 'adjust',
                    'created_at' => $now,
                ]);
            } else {
                if ($balance !== null) {
                    DB::table('inventory_balances')->where('variant_id', $data['variant_id'])->delete();
                }
                DB::table('product_variants')->where('id', $data['variant_id'])->update([
                    'tracks_inventory' => false, 'updated_at' => $now,
                ]);
            }
            app(RecordEvent::class)->record('platform.audit.recorded', 'catalog:inventory:'.$data['operation_key'], [
                'action' => 'catalog.inventory.adjusted', 'subject_type' => 'variant', 'subject_id' => $data['variant_id'],
                'actor_type' => 'staff', 'actor_id' => $actor->id,
                'metadata' => ['tracks_inventory' => $data['tracks_inventory']],
            ]);

            return $this->snapshot($data['variant_id']);
        });
    }

    /** @return array{variant_id: string, tracks_inventory: bool, on_hand: int|null, reserved: int, available_quantity: int|null, low_stock_threshold: int|null, low_stock: bool} */
    private function snapshot(string $variantId): array
    {
        $variant = DB::table('product_variants')->where('id', $variantId)->first();
        $balance = DB::table('inventory_balances')->where('variant_id', $variantId)->first();
        $tracks = (bool) ($variant->tracks_inventory ?? false);
        $onHand = $balance !== null ? (int) $balance->on_hand : null;
        $reserved = $balance !== null ? (int) $balance->reserved : 0;
        $available = $tracks && $onHand !== null ? $onHand - $reserved : null;
        $threshold = $balance !== null && $balance->low_stock_threshold !== null ? (int) $balance->low_stock_threshold : null;

        return [
            'variant_id' => $variantId,
            'tracks_inventory' => $tracks,
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'available_quantity' => $available,
            'low_stock_threshold' => $threshold,
            'low_stock' => $tracks && $threshold !== null && $available !== null && $available <= $threshold,
        ];
    }
}
