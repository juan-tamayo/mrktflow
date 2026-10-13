<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Pricing\Actions\ApplyCoupon;
use App\Domain\Pricing\Actions\HoldCoupon;
use App\Domain\Pricing\Actions\ReadCoupons;
use App\Domain\Pricing\Actions\SaveCoupon;
use App\Domain\Pricing\Contracts\PriceCalculator;
use App\Domain\Pricing\Data\CouponBasketLine;
use App\Domain\Pricing\Data\CouponHold;
use App\Domain\Pricing\Data\PricedLine;
use App\Domain\Pricing\Data\PriceRequest;
use App\Domain\Pricing\Exceptions\PricingCouponConflict;
use App\Domain\Pricing\Exceptions\PricingCouponExhausted;
use App\Domain\Pricing\Exceptions\PricingCouponRejected;
use App\Domain\Pricing\Exceptions\PricingInvalidQuote;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

it('PricingCoupon lets merchants configure one-coupon rules and quotes only eligible lines', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $lighting = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $lamp = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'category_ids' => [$lighting['id']],
    ]));
    $guide = app(SaveProduct::class)->save($owner, catalogDigitalProductInput($image, ['status' => 'published']));
    $lampVariant = (string) DB::table('product_variants')->where('product_id', $lamp['id'])->value('id');
    $guideVariant = (string) DB::table('product_variants')->where('product_id', $guide['id'])->value('id');
    $coupon = app(SaveCoupon::class)->save($owner, pricingCouponInput([
        'code' => 'lamp-10',
        'mode' => 'percentage',
        'percent_numerator' => 10,
        'category_ids' => [$lighting['id']],
        'max_redemptions' => 25,
        'max_redemptions_per_customer' => 2,
    ]));
    expect($coupon['code'])->toBe('LAMP-10')
        ->and($coupon['revision'])->toBe(1)
        ->and(DB::table('coupons')->where('id', $coupon['id'])->value('code_normalized'))->toBe('LAMP-10');
    $lampLine = pricingCouponLine($lamp['id'], $lampVariant, 2, pricingCouponUsd(1000), [$lighting['id']]);
    $guideLine = pricingCouponLine($guide['id'], $guideVariant, 1, pricingCouponUsd(500));
    $applied = app(ApplyCoupon::class)->apply('lamp-10', [$lampLine, $guideLine], new DateTimeImmutable('2026-10-13T00:00:00Z'));
    expect($applied->mode)->toBe('percentage')
        ->and($applied->waivesShipping)->toBeFalse()
        ->and($applied->eligibleLineIds)->toBe([$lampLine->line->lineId]);
    $quote = app(PriceCalculator::class)->quote(new PriceRequest(
        [$lampLine->line, $guideLine->line],
        new DateTimeImmutable('2026-10-13T00:00:00Z'),
        $applied->discount,
    ));
    expect($quote->merchandiseDiscount->amount)->toBe(200)
        ->and($quote->discountedMerchandise->amount)->toBe(2300);
    expect(fn () => app(ApplyCoupon::class)->apply('LAMP-10', [$guideLine], new DateTimeImmutable('2026-10-13T00:00:00Z')))
        ->toThrow(PricingCouponRejected::class);
    expect(fn () => app(ApplyCoupon::class)->apply('LAMP-10', [$lampLine], new DateTimeImmutable('2025-12-31T23:59:59Z')))
        ->toThrow(PricingCouponRejected::class);
    $this->get('/')->assertStatus(503);
});

it('PricingCoupon holds commits and releases redemptions atomically without oversubscription', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $product = app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    $variantId = (string) DB::table('product_variants')->where('product_id', $product['id'])->value('id');
    $coupon = pricingSavedCoupon($owner, ['code' => 'ONCE', 'mode' => 'fixed', 'amount' => 100, 'percent_numerator' => null]);
    $line = pricingCouponLine($product['id'], $variantId, 1, pricingCouponUsd(1000));
    $applied = app(ApplyCoupon::class)->apply('once', [$line], new DateTimeImmutable('2026-10-13T00:00:00Z'));
    $holder = app(HoldCoupon::class);
    $intent = (string) Str::uuid();
    $holdKey = (string) Str::uuid();
    $customer = (string) Str::uuid();
    $held = $holder->hold(new CouponHold(
        $intent,
        $holdKey,
        $coupon['id'],
        $applied->revision,
        $customer,
        now()->addMinutes(30)->toDateTimeImmutable(),
    ));
    $replay = $holder->hold(new CouponHold(
        $intent,
        $holdKey,
        $coupon['id'],
        $applied->revision,
        $customer,
        now()->addMinutes(30)->toDateTimeImmutable(),
    ));
    expect($replay->holdId)->toBe($held->holdId)
        ->and($held->status)->toBe('active')
        ->and((int) DB::table('coupons')->where('id', $coupon['id'])->value('held_count'))->toBe(1);
    expect(fn () => $holder->hold(new CouponHold(
        $intent,
        $holdKey,
        $coupon['id'],
        $applied->revision,
        $customer,
        now()->addMinutes(30)->toDateTimeImmutable(),
    )))->not->toThrow(PricingCouponConflict::class);
    expect(fn () => $holder->hold(new CouponHold(
        $intent,
        $holdKey,
        $coupon['id'],
        $applied->revision + 1,
        $customer,
        now()->addMinutes(30)->toDateTimeImmutable(),
    )))->toThrow(PricingCouponConflict::class);
    $otherIntent = (string) Str::uuid();
    expect(fn () => $holder->hold(new CouponHold(
        $otherIntent,
        (string) Str::uuid(),
        $coupon['id'],
        $applied->revision,
        (string) Str::uuid(),
        now()->addMinutes(30)->toDateTimeImmutable(),
    )))->toThrow(PricingCouponExhausted::class);
    $commitKey = (string) Str::uuid();
    $committed = $holder->commit($intent, $commitKey);
    expect($committed->status)->toBe('committed')
        ->and((int) DB::table('coupons')->where('id', $coupon['id'])->value('held_count'))->toBe(0)
        ->and((int) DB::table('coupons')->where('id', $coupon['id'])->value('redeemed_count'))->toBe(1);
    $holder->commit($intent, $commitKey);
    expect(DB::table('coupon_redemptions')->count())->toBe(1);
    expect(fn () => $holder->release($intent, (string) Str::uuid()))->toThrow(PricingCouponRejected::class);
    $second = pricingSavedCoupon($owner, [
        'code' => 'TWICE', 'max_redemptions' => 2, 'max_redemptions_per_customer' => 1,
    ]);
    $firstHold = $holder->hold(new CouponHold(
        (string) Str::uuid(),
        (string) Str::uuid(),
        $second['id'],
        $second['revision'],
        $customer,
        now()->addMinutes(30)->toDateTimeImmutable(),
    ));
    expect(fn () => $holder->hold(new CouponHold(
        (string) Str::uuid(),
        (string) Str::uuid(),
        $second['id'],
        $second['revision'],
        $customer,
        now()->addMinutes(30)->toDateTimeImmutable(),
    )))->toThrow(PricingCouponExhausted::class);
    $released = $holder->release($firstHold->intentId, (string) Str::uuid());
    expect($released->status)->toBe('released')
        ->and((int) DB::table('coupons')->where('id', $second['id'])->value('held_count'))->toBe(0);
    $holder->hold(new CouponHold(
        (string) Str::uuid(),
        (string) Str::uuid(),
        $second['id'],
        $second['revision'],
        $customer,
        now()->addMinutes(30)->toDateTimeImmutable(),
    ));
    expect((int) DB::table('coupons')->where('id', $second['id'])->value('held_count'))->toBe(1);
});

it('PricingCoupon serializes parallel last-redemption holds across real MySQL connections', function (): void {
    $owner = catalogInstalledOwner();
    $coupon = pricingSavedCoupon($owner, ['code' => 'LAST', 'max_redemptions' => 1, 'max_redemptions_per_customer' => null]);
    $processes = [];
    $readyFiles = [];
    $start = function () use ($coupon, &$processes, &$readyFiles): Process {
        $id = (string) Str::uuid();
        $ready = base_path('.runtime/coupon-'.$id.'.ready');
        $readyFiles[] = $ready;
        $process = new Process(
            [base_path('bin/php'), base_path('tests/Fixtures/coupon-hold-race.php'), $id, '--env=testing'],
            base_path(),
            ['APP_ENV' => 'testing'],
        );
        $process->setInput(json_encode([
            'coupon_id' => $coupon['id'],
            'revision' => $coupon['revision'],
            'intent_id' => (string) Str::uuid(),
            'operation_key' => (string) Str::uuid(),
            'customer_key' => (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(15)->start();
        $processes[] = $process;

        return $process;
    };
    try {
        DB::beginTransaction();
        DB::table('coupons')->where('id', $coupon['id'])->lockForUpdate()->first();
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
        expect($statuses)->toBe(['exhausted', 'held'])
            ->and(DB::table('coupon_holds')->where('status', 'active')->count())->toBe(1)
            ->and((int) DB::table('coupons')->where('id', $coupon['id'])->value('held_count'))->toBe(1)
            ->and((int) DB::table('coupons')->where('id', $coupon['id'])->value('redeemed_count'))->toBe(0);
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

it('PricingCoupon HTTP keeps privileged staff behind two-factor and rejects customers', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/coupons')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/coupons', ['coupon' => pricingCouponInput()])->assertForbidden();
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->postJson('/admin/coupons', ['coupon' => pricingCouponInput()])
        ->assertUnauthorized();
    $this->get('/')->assertStatus(503);
});

it('PricingCoupon HTTP lets authorized staff save coupons and denies other roles', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canManagePricing', true));
    $this->get('/admin/coupons/create')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('CouponEdit')
        ->where('coupon', null));
    $this->post('/admin/coupons', ['coupon' => pricingCouponInput(['code' => 'WELCOME'])])
        ->assertRedirect();
    $id = (string) DB::table('coupons')->value('id');
    $this->get('/admin/coupons/'.$id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('CouponEdit')
        ->where('coupon.code', 'WELCOME')
        ->where('coupon.status', 'active'));
    $this->from('/admin/coupons/create')->post('/admin/coupons', ['coupon' => pricingCouponInput(['code' => 'welcome'])])
        ->assertRedirect('/admin/coupons/create')->assertSessionHasErrors();
    foreach (['content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()))
            ->postJson('/admin/coupons', ['coupon' => pricingCouponInput(['code' => 'DENIED'])])
            ->assertForbidden();
    }
    $this->get('/')->assertStatus(503);
});

it('PricingCoupon HTTP rejects forged coupon saves', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->app->instance('env', 'local');
    $this->postJson('/admin/coupons', ['coupon' => pricingCouponInput()])->assertStatus(419);
    $this->app->instance('env', 'testing');
});

it('PricingCoupon rejects invalid configuration holds and ineligible quotes', function (): void {
    $owner = catalogInstalledOwner();
    $denied = $owner->fresh();
    $denied->forceFill(['role' => 'fulfillment', 'permissions' => StaffPermissions::forRole('fulfillment')])->save();
    expect(fn () => app(SaveCoupon::class)->save($denied, pricingCouponInput(['code' => 'NOPE'])))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveCoupon::class)->save($owner, pricingCouponInput([
        'code' => 'FIX', 'mode' => 'fixed', 'amount' => null, 'percent_numerator' => null,
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveCoupon::class)->save($owner, pricingCouponInput([
        'code' => 'PCT', 'percent_numerator' => null,
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveCoupon::class)->save($owner, pricingCouponInput([
        'code' => 'OVER', 'percent_numerator' => 150, 'percent_denominator' => 100,
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveCoupon::class)->save($owner, pricingCouponInput([
        'id' => (string) Str::uuid(), 'version' => 1, 'code' => 'GONE',
    ])))->toThrow(HttpException::class);
    $image = catalogReadyImage($owner->id);
    $parent = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $child = app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'title' => 'Bulbs', 'slug' => 'bulbs', 'status' => 'published', 'parent_id' => $parent['id'],
    ]));
    $product = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'category_ids' => [$child['id']],
    ]));
    $variantId = (string) DB::table('product_variants')->where('product_id', $product['id'])->value('id');
    $line = pricingCouponLine($product['id'], $variantId, 1, pricingCouponUsd(1000), [$child['id']]);
    expect(fn () => app(SaveCoupon::class)->save($owner, pricingCouponInput([
        'code' => 'MISS', 'product_ids' => [(string) Str::uuid()],
    ])))->toThrow(HttpException::class);
    $fixed = app(SaveCoupon::class)->save($owner, pricingCouponInput([
        'code' => 'lampfix',
        'mode' => 'fixed',
        'amount' => 100,
        'percent_numerator' => '',
        'percent_denominator' => '',
        'starts_at' => '',
        'ends_at' => '',
        'max_redemptions' => '',
        'max_redemptions_per_customer' => '',
        'product_ids' => [$product['id']],
        'category_ids' => [$parent['id']],
    ]));
    $updated = app(SaveCoupon::class)->save($owner, pricingCouponInput([
        'id' => $fixed['id'],
        'version' => $fixed['revision'],
        'code' => 'LAMPFIX',
        'mode' => 'fixed',
        'amount' => 150,
        'percent_numerator' => null,
        'max_redemptions' => 5,
        'max_redemptions_per_customer' => null,
        'product_ids' => [$product['id']],
        'category_ids' => [],
    ]));
    expect($updated['revision'])->toBe(2);
    expect(fn () => app(SaveCoupon::class)->save($owner, pricingCouponInput([
        'id' => $fixed['id'], 'version' => 1, 'code' => 'STALE', 'mode' => 'fixed', 'amount' => 150, 'percent_numerator' => null,
    ])))->toThrow(HttpException::class);
    $other = pricingSavedCoupon($owner, ['code' => 'OTHER']);
    expect(fn () => app(SaveCoupon::class)->save($owner, pricingCouponInput([
        'id' => $other['id'], 'version' => $other['revision'], 'code' => 'LAMPFIX',
    ])))->toThrow(ValidationException::class);
    $quoted = new DateTimeImmutable('2026-10-13T00:00:00Z');
    $applied = app(ApplyCoupon::class)->apply('lampfix', [$line], $quoted);
    expect($applied->mode)->toBe('fixed')->and($applied->eligibleLineIds)->toBe([$line->line->lineId]);
    $nested = pricingSavedCoupon($owner, [
        'code' => 'NEST', 'category_ids' => [$parent['id']], 'max_redemptions' => 10, 'max_redemptions_per_customer' => null,
    ]);
    expect(app(ApplyCoupon::class)->apply('NEST', [$line], $quoted)->code)->toBe('NEST');
    $shipping = pricingSavedCoupon($owner, [
        'code' => 'SHIP', 'mode' => 'free_shipping', 'amount' => null, 'percent_numerator' => null, 'max_redemptions' => 10,
    ]);
    $free = app(ApplyCoupon::class)->apply('SHIP', [$line], $quoted);
    expect($free->waivesShipping)->toBeTrue()->and($free->discount->mode)->toBe('none');
    $paused = pricingSavedCoupon($owner, ['code' => 'STOP', 'status' => 'paused']);
    expect(fn () => app(ApplyCoupon::class)->apply('STOP', [$line], $quoted))->toThrow(PricingCouponRejected::class);
    $spend = pricingSavedCoupon($owner, ['code' => 'BIG', 'min_eligible_subtotal' => 5000]);
    expect(fn () => app(ApplyCoupon::class)->apply('BIG', [$line], $quoted))->toThrow(PricingCouponRejected::class);
    $tooMuch = pricingSavedCoupon($owner, [
        'code' => 'HUGE', 'mode' => 'fixed', 'amount' => 5000, 'percent_numerator' => null,
    ]);
    expect(fn () => app(ApplyCoupon::class)->apply('HUGE', [$line], $quoted))->toThrow(PricingCouponRejected::class);
    expect(fn () => app(ApplyCoupon::class)->apply('', [$line], $quoted))->toThrow(PricingCouponRejected::class);
    expect(fn () => app(ApplyCoupon::class)->apply('LAMPFIX', [], $quoted))->toThrow(PricingCouponRejected::class);
    expect(fn () => app(ApplyCoupon::class)->apply('LAMPFIX', [$line], $quoted, strtoupper((string) Str::uuid())))
        ->toThrow(PricingCouponRejected::class);
    expect(fn () => app(ApplyCoupon::class)->apply('OTHER', [$line], new DateTimeImmutable('2026-12-31T00:00:00Z')))
        ->toThrow(PricingCouponRejected::class);
    $holder = app(HoldCoupon::class);
    $customer = (string) Str::uuid();
    $capped = pricingSavedCoupon($owner, ['code' => 'CAP1', 'max_redemptions' => 1, 'max_redemptions_per_customer' => null]);
    $holder->hold(new CouponHold(
        (string) Str::uuid(), (string) Str::uuid(), $capped['id'], $capped['revision'], $customer, now()->addMinutes(15)->toDateTimeImmutable(),
    ));
    expect(fn () => app(ApplyCoupon::class)->apply('CAP1', [$line], $quoted))->toThrow(PricingCouponExhausted::class);
    $room = pricingSavedCoupon($owner, ['code' => 'ROOM', 'max_redemptions' => 5, 'max_redemptions_per_customer' => null]);
    $holder->hold(new CouponHold(
        (string) Str::uuid(), (string) Str::uuid(), $room['id'], $room['revision'], $customer, now()->addMinutes(15)->toDateTimeImmutable(),
    ));
    $holder->hold(new CouponHold(
        (string) Str::uuid(), (string) Str::uuid(), $room['id'], $room['revision'], (string) Str::uuid(), now()->addMinutes(15)->toDateTimeImmutable(),
    ));
    expect(fn () => app(SaveCoupon::class)->save($owner, pricingCouponInput([
        'id' => $room['id'], 'version' => $room['revision'], 'code' => 'ROOM', 'max_redemptions' => 1, 'max_redemptions_per_customer' => null,
    ])))->toThrow(ValidationException::class);
    $limited = pricingSavedCoupon($owner, ['code' => 'ONCEYOU', 'max_redemptions' => 5, 'max_redemptions_per_customer' => 1]);
    $holder->hold(new CouponHold(
        (string) Str::uuid(), (string) Str::uuid(), $limited['id'], $limited['revision'], $customer, now()->addMinutes(15)->toDateTimeImmutable(),
    ));
    expect(fn () => app(ApplyCoupon::class)->apply('ONCEYOU', [$line], $quoted, $customer))->toThrow(PricingCouponExhausted::class);
    expect(fn () => $holder->hold(new CouponHold(
        (string) Str::uuid(), (string) Str::uuid(), $paused['id'], $paused['revision'], $customer, now()->addMinutes(15)->toDateTimeImmutable(),
    )))->toThrow(PricingCouponRejected::class);
    expect(fn () => $holder->hold(new CouponHold(
        (string) Str::uuid(), (string) Str::uuid(), $limited['id'], $limited['revision'], null, now()->addMinutes(15)->toDateTimeImmutable(),
    )))->toThrow(PricingCouponRejected::class);
    $intent = (string) Str::uuid();
    $firstKey = (string) Str::uuid();
    $open = pricingSavedCoupon($owner, ['code' => 'OPEN', 'max_redemptions' => 3, 'max_redemptions_per_customer' => null]);
    $holder->hold(new CouponHold($intent, $firstKey, $open['id'], $open['revision'], $customer, now()->addMinutes(15)->toDateTimeImmutable()));
    expect(fn () => $holder->hold(new CouponHold(
        $intent, (string) Str::uuid(), $open['id'], $open['revision'], $customer, now()->addMinutes(15)->toDateTimeImmutable(),
    )))->toThrow(PricingCouponConflict::class);
    $releaseKey = (string) Str::uuid();
    $released = $holder->release($intent, $releaseKey);
    expect($holder->release($intent, $releaseKey)->status)->toBe($released->status);
    expect(fn () => $holder->commit($intent, (string) Str::uuid()))->toThrow(PricingCouponRejected::class);
    expect(fn () => $holder->commit((string) Str::uuid(), (string) Str::uuid()))->toThrow(PricingCouponRejected::class);
    expect(fn () => $holder->release((string) Str::uuid(), (string) Str::uuid()))->toThrow(PricingCouponRejected::class);
    expect(fn () => $holder->commit(strtoupper((string) Str::uuid()), (string) Str::uuid()))->toThrow(InvalidArgumentException::class);
    $freshIntent = (string) Str::uuid();
    $commitKey = (string) Str::uuid();
    $holder->hold(new CouponHold(
        $freshIntent, (string) Str::uuid(), $open['id'], $open['revision'], $customer, now()->addMinutes(15)->toDateTimeImmutable(),
    ));
    $holder->commit($freshIntent, $commitKey);
    DB::table('coupon_redemptions')->where('intent_id', $freshIntent)->delete();
    DB::table('coupon_holds')->where('intent_id', $freshIntent)->delete();
    expect(fn () => $holder->commit($freshIntent, $commitKey))->toThrow(PricingCouponRejected::class);
    $clashIntent = (string) Str::uuid();
    $clashKey = (string) Str::uuid();
    $holder->hold(new CouponHold(
        $clashIntent, $clashKey, $open['id'], $open['revision'], $customer, now()->addMinutes(15)->toDateTimeImmutable(),
    ));
    $otherIntent = (string) Str::uuid();
    $holder->hold(new CouponHold(
        $otherIntent, (string) Str::uuid(), $open['id'], $open['revision'], (string) Str::uuid(), now()->addMinutes(15)->toDateTimeImmutable(),
    ));
    expect(fn () => $holder->commit($otherIntent, $clashKey))->toThrow(PricingCouponConflict::class);
    expect(fn () => new CouponHold('not-a-uuid', (string) Str::uuid(), $open['id'], 1, null, now()->toDateTimeImmutable()))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new CouponBasketLine(
        new PricedLine((string) Str::uuid(), $variantId, 1, pricingCouponUsd(100)),
        strtoupper($product['id']),
    ))->toThrow(PricingInvalidQuote::class);
    expect(fn () => new CouponBasketLine(
        new PricedLine((string) Str::uuid(), $variantId, 1, pricingCouponUsd(100)),
        $product['id'],
        [$child['id'], $child['id']],
    ))->toThrow(PricingInvalidQuote::class);
    expect(fn () => app(ReadCoupons::class)->staffCoupon((string) Str::uuid()))->toThrow(NotFoundHttpException::class);
    $detail = app(ReadCoupons::class)->staffCoupon($fixed['id']);
    expect($detail['product_ids'])->toBe([$product['id']])
        ->and(app(ReadCoupons::class)->staffCoupons()[0]['code'])->toBeString()
        ->and(app(ReadCoupons::class)->catalogOptions()['products'])->not->toBeEmpty();
    $this->get('/')->assertStatus(503);
});

it('PricingCoupon HTTP lists edits and rejects missing or stale coupons', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->post('/admin/coupons', ['coupon' => pricingCouponInput(['code' => 'LISTME'])])->assertRedirect();
    $id = (string) DB::table('coupons')->value('id');
    $this->get('/admin/coupons')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('CouponIndex')
        ->has('coupons', 1)
        ->where('coupons.0.code', 'LISTME')
        ->where('canWrite', true));
    $this->get('/admin/coupons/'.(string) Str::uuid())->assertNotFound();
    $this->patch('/admin/coupons/'.$id, ['coupon' => pricingCouponInput([
        'code' => 'LISTED', 'version' => 1, 'name' => 'Studio welcome edited',
    ])])->assertRedirect('/admin/coupons/'.$id);
    $this->from('/admin/coupons/'.$id)->patch('/admin/coupons/'.$id, ['coupon' => pricingCouponInput([
        'code' => 'STALE2', 'version' => 1, 'name' => 'Stale edit',
    ])])->assertRedirect('/admin/coupons/'.$id)->assertSessionHasErrors('coupon');
    $this->get('/admin/coupons/'.$id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('CouponEdit')
        ->where('coupon.code', 'LISTED')
        ->where('coupon.version', 2)
        ->where('saved', false));
    $this->get('/')->assertStatus(503);
});
