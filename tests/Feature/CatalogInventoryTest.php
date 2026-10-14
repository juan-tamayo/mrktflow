<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveVariants;
use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\Catalog\Contracts\InventoryAllocator;
use App\Domain\Catalog\Data\InventoryHold;
use App\Domain\Catalog\Data\InventoryLine;
use App\Domain\Catalog\Data\InventoryMutation;
use App\Domain\Catalog\Exceptions\CatalogInsufficientStock;
use App\Domain\Catalog\Exceptions\CatalogInvalidAllocation;
use App\Domain\Catalog\Exceptions\CatalogInventoryConflict;
use App\Domain\Catalog\Exceptions\CatalogInventoryUntracked;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\StaffPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

it('CatalogInventory tracks on-hand reserved remaining and rejects adjustments below reserved', function (): void {
    $owner = catalogInstalledOwner();
    $tracked = catalogTrackedVariant($owner, 2, 1);
    $allocator = app(InventoryAllocator::class);
    $hold = $allocator->hold(new InventoryHold(
        (string) Str::uuid(),
        (string) Str::uuid(),
        now()->addMinutes(30)->toDateTimeImmutable(),
        [new InventoryLine($tracked['variant_id'], 1)],
    ));
    expect($hold->allocations[0]->original)->toBe(1)
        ->and($hold->allocations[0]->shipped)->toBe(0)
        ->and($hold->allocations[0]->released)->toBe(0)
        ->and($hold->allocations[0]->remaining)->toBe(1)
        ->and($hold->allocations[0]->status)->toBe('active');
    expect((int) DB::table('inventory_balances')->where('variant_id', $tracked['variant_id'])->value('on_hand'))->toBe(2)
        ->and((int) DB::table('inventory_balances')->where('variant_id', $tracked['variant_id'])->value('reserved'))->toBe(1);
    expect(fn () => app(AdjustStock::class)->adjust($owner, [
        'variant_id' => $tracked['variant_id'],
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 0,
        'low_stock_threshold' => 1,
    ]))->toThrow(ValidationException::class);
    expect(DB::table('inventory_balances')->where('variant_id', $tracked['variant_id'])->value('on_hand'))->toBe(2);
    $staff = app(CatalogReader::class)->staffProduct($tracked['product_id']);
    expect($staff['variants'][0]['tracks_inventory'])->toBeTrue()
        ->and($staff['variants'][0]['on_hand'])->toBe(2)
        ->and($staff['variants'][0]['reserved'])->toBe(1)
        ->and($staff['variants'][0]['available_quantity'])->toBe(1)
        ->and($staff['variants'][0]['low_stock'])->toBeTrue();
    $public = app(CatalogReader::class)->publicProduct('studio-lamp');
    expect($public['variants'][0])->not->toHaveKeys(['on_hand', 'reserved', 'available_quantity'])
        ->and(json_encode($public))->not->toContain('inventory_balances');
    $this->get('/')->assertStatus(503);
});

it('CatalogInventory holds commits releases and ships atomically with conserved quantities and idempotent ledger writes', function (): void {
    $owner = catalogInstalledOwner();
    $tracked = catalogTrackedVariant($owner, 5, 2);
    $allocator = app(InventoryAllocator::class);
    $intent = (string) Str::uuid();
    $holdKey = (string) Str::uuid();
    $hold = $allocator->hold(new InventoryHold(
        $intent,
        $holdKey,
        now()->addMinutes(30)->toDateTimeImmutable(),
        [new InventoryLine($tracked['variant_id'], 4)],
    ));
    $replay = $allocator->hold(new InventoryHold(
        $intent,
        $holdKey,
        now()->addMinutes(30)->toDateTimeImmutable(),
        [new InventoryLine($tracked['variant_id'], 4)],
    ));
    expect($replay->allocations[0]->id)->toBe($hold->allocations[0]->id);
    expect(fn () => $allocator->hold(new InventoryHold(
        $intent,
        $holdKey,
        now()->addMinutes(30)->toDateTimeImmutable(),
        [new InventoryLine($tracked['variant_id'], 3)],
    )))->toThrow(CatalogInventoryConflict::class);
    $commitKey = (string) Str::uuid();
    $committed = $allocator->commit($intent, $commitKey);
    expect($committed->allocations[0]->status)->toBe('committed')
        ->and(DB::table('inventory_balances')->where('variant_id', $tracked['variant_id'])->value('reserved'))->toBe(4);
    $allocator->commit($intent, $commitKey);
    expect(DB::table('inventory_balances')->where('variant_id', $tracked['variant_id'])->value('reserved'))->toBe(4);
    $shipped = $allocator->ship(new InventoryMutation($intent, (string) Str::uuid(), [new InventoryLine($tracked['variant_id'], 1)]));
    expect($shipped->allocations[0]->original)->toBe(4)
        ->and($shipped->allocations[0]->shipped)->toBe(1)
        ->and($shipped->allocations[0]->released)->toBe(0)
        ->and($shipped->allocations[0]->remaining)->toBe(3)
        ->and($shipped->allocations[0]->status)->toBe('committed');
    $released = $allocator->release(new InventoryMutation($intent, (string) Str::uuid(), [new InventoryLine($tracked['variant_id'], 2)]));
    $allocation = $released->allocations[0];
    expect($allocation->original)->toBe($allocation->shipped + $allocation->released + $allocation->remaining)
        ->and($allocation->shipped)->toBe(1)
        ->and($allocation->released)->toBe(2)
        ->and($allocation->remaining)->toBe(1)
        ->and($allocation->status)->toBe('committed');
    expect((int) DB::table('inventory_balances')->where('variant_id', $tracked['variant_id'])->value('on_hand'))->toBe(4)
        ->and((int) DB::table('inventory_balances')->where('variant_id', $tracked['variant_id'])->value('reserved'))->toBe(1);
    $closed = $allocator->ship(new InventoryMutation($intent, (string) Str::uuid(), [new InventoryLine($tracked['variant_id'], 1)]));
    expect($closed->allocations[0]->remaining)->toBe(0)
        ->and($closed->allocations[0]->status)->toBe('closed')
        ->and($closed->allocations[0]->original)->toBe(4);
    expect(DB::table('stock_movements')->where('variant_id', $tracked['variant_id'])->where('reason', '!=', 'adjust')->count())->toBe(5);
    $plain = app(SaveProduct::class)->save($owner, catalogProductInput(catalogReadyImage($owner->id), [
        'title' => 'Untracked Lamp', 'slug' => 'untracked-lamp', 'sku' => 'LAMP-FREE', 'status' => 'published',
    ]));
    $plainId = (string) DB::table('product_variants')->where('product_id', $plain['id'])->value('id');
    expect(fn () => $allocator->hold(new InventoryHold(
        (string) Str::uuid(),
        (string) Str::uuid(),
        now()->addMinutes(30)->toDateTimeImmutable(),
        [new InventoryLine($plainId, 1)],
    )))->toThrow(CatalogInventoryUntracked::class);
    expect(fn () => $allocator->ship(new InventoryMutation($intent, (string) Str::uuid(), [new InventoryLine($tracked['variant_id'], 1)])))
        ->toThrow(CatalogInvalidAllocation::class);
});

it('CatalogInventory rejects a second hold when the last unit is already reserved', function (): void {
    $owner = catalogInstalledOwner();
    $tracked = catalogTrackedVariant($owner, 1, null);
    $allocator = app(InventoryAllocator::class);
    $allocator->hold(new InventoryHold(
        (string) Str::uuid(),
        (string) Str::uuid(),
        now()->addMinutes(30)->toDateTimeImmutable(),
        [new InventoryLine($tracked['variant_id'], 1)],
    ));
    expect(fn () => $allocator->hold(new InventoryHold(
        (string) Str::uuid(),
        (string) Str::uuid(),
        now()->addMinutes(30)->toDateTimeImmutable(),
        [new InventoryLine($tracked['variant_id'], 1)],
    )))->toThrow(CatalogInsufficientStock::class);
    expect(DB::table('stock_reservations')->count())->toBe(1);
});

it('CatalogInventory serializes parallel last-unit holds across real MySQL connections', function (): void {
    $owner = catalogInstalledOwner();
    $tracked = catalogTrackedVariant($owner, 1, null);
    $processes = [];
    $readyFiles = [];
    $start = function () use ($tracked, &$processes, &$readyFiles): Process {
        $id = (string) Str::uuid();
        $ready = base_path('.runtime/inventory-'.$id.'.ready');
        $readyFiles[] = $ready;
        $process = new Process(
            [base_path('bin/php'), base_path('tests/Fixtures/inventory-hold-race.php'), $id, '--env=testing'],
            base_path(),
            ['APP_ENV' => 'testing'],
        );
        $process->setInput(json_encode([
            'variant_id' => $tracked['variant_id'],
            'intent_id' => (string) Str::uuid(),
            'operation_key' => (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(15)->start();
        $processes[] = $process;

        return $process;
    };
    try {
        DB::beginTransaction();
        DB::table('inventory_balances')->where('variant_id', $tracked['variant_id'])->lockForUpdate()->first();
        $start();
        $start();
        $deadline = microtime(true) + 8;
        while ((! is_file($readyFiles[0]) || ! is_file($readyFiles[1])) && microtime(true) < $deadline) {
            expect($processes[0]->isRunning() && $processes[1]->isRunning())->toBeTrue();
            usleep(20000);
        }
        expect(is_file($readyFiles[0]) && is_file($readyFiles[1]))->toBeTrue();
        DB::commit();
        $statuses = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0);
            $statuses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
        }
        sort($statuses);
        expect($statuses)->toBe(['held', 'insufficient'])
            ->and(DB::table('stock_reservations')->count())->toBe(1)
            ->and((int) DB::table('inventory_balances')->where('variant_id', $tracked['variant_id'])->value('reserved'))->toBe(1)
            ->and((int) DB::table('inventory_balances')->where('variant_id', $tracked['variant_id'])->value('on_hand'))->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        foreach ($readyFiles as $ready) {
            if (is_file($ready)) {
                unlink($ready);
            }
        }
    }
});

it('CatalogInventory HTTP keeps privileged staff behind two-factor and rejects customers', function (): void {
    $owner = catalogInstalledOwner();
    $tracked = catalogTrackedVariant($owner, 3, 1);
    $payload = [
        'variant_id' => $tracked['variant_id'],
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 8,
        'low_stock_threshold' => 2,
    ];
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/products/'.$tracked['product_id'])->assertRedirect('/admin/two-factor');
    $this->patchJson('/admin/products/'.$tracked['product_id'].'/inventory', $payload)->assertForbidden();
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->patchJson('/admin/products/'.$tracked['product_id'].'/inventory', $payload)
        ->assertUnauthorized();
});

it('CatalogInventory HTTP lets authorized staff set tracked stock and shows a low-stock indicator', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $saved = app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    $variantId = (string) DB::table('product_variants')->where('product_id', $saved['id'])->value('id');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/products/'.$saved['id'])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ProductEdit')
        ->where('product.variants.0.tracks_inventory', false)
        ->where('product.variants.0.on_hand', null));
    $this->patch('/admin/products/'.$saved['id'].'/inventory', [
        'variant_id' => $variantId,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 2,
        'low_stock_threshold' => 3,
    ])->assertRedirect('/admin/products/'.$saved['id']);
    $this->get('/admin/products/'.$saved['id'])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('product.variants.0.tracks_inventory', true)
        ->where('product.variants.0.on_hand', 2)
        ->where('product.variants.0.low_stock', true)
        ->where('product.variants.0.available_quantity', 2));
    $this->from('/admin/products/'.$saved['id'])->patch('/admin/products/'.$saved['id'].'/inventory', [
        'variant_id' => $variantId,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => -1,
        'low_stock_threshold' => 3,
    ])->assertRedirect('/admin/products/'.$saved['id'])->assertSessionHasErrors();
    foreach (['content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()))
            ->patchJson('/admin/products/'.$saved['id'].'/inventory', [
                'variant_id' => $variantId,
                'operation_key' => (string) Str::uuid(),
                'tracks_inventory' => true,
                'on_hand' => 4,
                'low_stock_threshold' => 1,
            ])->assertForbidden();
    }
});

it('CatalogInventory HTTP rejects forged stock saves and does not flash nested inventory payloads', function (): void {
    $owner = catalogInstalledOwner();
    $tracked = catalogTrackedVariant($owner, 3, 1);
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->app->instance('env', 'local');
    $this->patchJson('/admin/products/'.$tracked['product_id'].'/inventory', [
        'variant_id' => $tracked['variant_id'],
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 9,
        'low_stock_threshold' => 1,
    ])->assertStatus(419);
    $this->app->instance('env', 'testing');
    $this->from('/admin/products/'.$tracked['product_id'])->patch('/admin/products/'.$tracked['product_id'].'/inventory', [
        'variant_id' => $tracked['variant_id'],
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => -4,
        'low_stock_threshold' => 1,
    ])->assertRedirect('/admin/products/'.$tracked['product_id'])->assertSessionHasErrors()->assertSessionMissing('_old_input.on_hand');
});

it('CatalogInventory refuses to replace a variant that still has reserved units', function (): void {
    $owner = catalogInstalledOwner();
    $tracked = catalogTrackedVariant($owner, 2, null);
    app(InventoryAllocator::class)->hold(new InventoryHold(
        (string) Str::uuid(),
        (string) Str::uuid(),
        now()->addMinutes(30)->toDateTimeImmutable(),
        [new InventoryLine($tracked['variant_id'], 1)],
    ));
    $image = catalogReadyImage($owner->id);
    expect(fn () => app(SaveVariants::class)->save($owner, catalogVariantMatrix($tracked['product_id'], $tracked['version'], $image)))
        ->toThrow(ValidationException::class);
    expect(DB::table('product_variants')->where('id', $tracked['variant_id'])->exists())->toBeTrue();
});
