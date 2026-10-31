<?php

declare(strict_types=1);

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Shipping\Actions\PreviewShipping;
use App\Domain\Shipping\Actions\ReadShippingRules;
use App\Domain\Shipping\Actions\SaveShippingRules;
use App\Domain\Shipping\Data\ShippingDestination;
use App\Domain\Shipping\Exceptions\ShippingRuleConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

it('ShippingRules lets merchants configure origin zones and method types', function (): void {
    $owner = catalogInstalledOwner();
    $saved = app(SaveShippingRules::class)->save($owner, shippingRulesInput([
        'classes' => [
            ['key' => 'general', 'name' => 'General'],
            ['key' => 'fragile', 'name' => 'Fragile'],
        ],
        'zones' => [
            shippingZoneInput([
                'name' => 'California 90',
                'priority' => 5,
                'matches' => [shippingMatchInput(['region_code' => 'CA', 'postal_pattern' => '90*'])],
                'methods' => [
                    shippingMethodInput(['label' => 'Local ground', 'amount' => 499]),
                    shippingMethodInput([
                        'label' => 'Free over fifty',
                        'type' => 'free',
                        'amount' => null,
                        'free_min_subtotal' => 5000,
                    ]),
                ],
            ]),
            shippingZoneInput([
                'name' => 'United States',
                'priority' => 20,
                'methods' => [
                    shippingMethodInput(['label' => 'US ground', 'amount' => 799]),
                    shippingMethodInput([
                        'label' => 'Weight table',
                        'type' => 'table',
                        'amount' => null,
                        'table_basis' => 'weight',
                        'table_rows' => [
                            ['min' => 0, 'max' => 500, 'amount' => 500],
                            ['min' => 500, 'max' => null, 'amount' => 1200],
                        ],
                    ]),
                    shippingMethodInput([
                        'label' => 'Warehouse pickup',
                        'type' => 'pickup',
                        'amount' => null,
                        'pickup' => [
                            'name' => 'Example Supply dock',
                            'street' => '100 Warehouse Ave',
                            'city' => 'Los Angeles',
                            'region_code' => 'CA',
                            'postal_code' => '90001',
                            'country_code' => 'US',
                            'instructions' => 'Use the dock door after payment.',
                        ],
                    ]),
                ],
            ]),
        ],
    ]));
    expect($saved['origin']['city'])->toBe('Los Angeles')
        ->and($saved['revision'])->toBe(1)
        ->and(DB::table('shipping_classes')->where('class_key', 'general')->exists())->toBeTrue()
        ->and(DB::table('shipping_classes')->where('class_key', 'fragile')->exists())->toBeTrue()
        ->and(DB::table('shipping_zones')->count())->toBe(2)
        ->and(DB::table('shipping_methods')->where('type', 'pickup')->count())->toBe(1);
    $loaded = app(ReadShippingRules::class)->staffRules();
    expect($loaded['origin']['postal_code'])->toBe('90001')
        ->and($loaded['zones'][0]['name'])->toBe('California 90');
    $this->get('/')->assertOk();
});

it('ShippingRules rejects overlapping same-priority destination rules', function (): void {
    $owner = catalogInstalledOwner();
    expect(fn () => app(SaveShippingRules::class)->save($owner, shippingRulesInput([
        'zones' => [
            shippingZoneInput([
                'name' => 'US catch-all',
                'priority' => 10,
                'matches' => [shippingMatchInput()],
            ]),
            shippingZoneInput([
                'name' => 'US California',
                'priority' => 10,
                'matches' => [shippingMatchInput(['region_code' => 'CA'])],
            ]),
        ],
    ])))->toThrow(ShippingRuleConflict::class);
    expect(fn () => app(SaveShippingRules::class)->save($owner, shippingRulesInput([
        'zones' => [
            shippingZoneInput([
                'name' => 'Prefix',
                'priority' => 8,
                'matches' => [shippingMatchInput(['postal_pattern' => '90*'])],
            ]),
            shippingZoneInput([
                'name' => 'Exact',
                'priority' => 8,
                'matches' => [shippingMatchInput(['postal_pattern' => '90210'])],
            ]),
        ],
    ])))->toThrow(ShippingRuleConflict::class);
    $ok = app(SaveShippingRules::class)->save($owner, shippingRulesInput([
        'zones' => [
            shippingZoneInput([
                'name' => 'California',
                'priority' => 5,
                'matches' => [shippingMatchInput(['region_code' => 'CA'])],
            ]),
            shippingZoneInput([
                'name' => 'New York',
                'priority' => 5,
                'matches' => [shippingMatchInput(['region_code' => 'NY'])],
            ]),
        ],
    ]));
    expect($ok['revision'])->toBe(1);
});

it('ShippingRules previews boundary postal codes and free or table thresholds', function (): void {
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [
            shippingZoneInput([
                'name' => 'California 90',
                'priority' => 5,
                'matches' => [shippingMatchInput(['region_code' => 'ca', 'postal_pattern' => '90*'])],
                'methods' => [
                    shippingMethodInput(['label' => 'Local ground', 'amount' => 499]),
                    shippingMethodInput([
                        'label' => 'Free over fifty',
                        'type' => 'free',
                        'amount' => null,
                        'free_min_subtotal' => 5000,
                    ]),
                ],
            ]),
            shippingZoneInput([
                'name' => 'United States',
                'priority' => 20,
                'methods' => [
                    shippingMethodInput(['label' => 'US ground', 'amount' => 799]),
                    shippingMethodInput([
                        'label' => 'Weight table',
                        'type' => 'table',
                        'amount' => null,
                        'table_basis' => 'weight',
                        'table_rows' => [
                            ['min' => 0, 'max' => 500, 'amount' => 500],
                            ['min' => 500, 'max' => null, 'amount' => 1200],
                        ],
                    ]),
                ],
            ]),
        ],
    ]);
    $preview = app(PreviewShipping::class);
    $inside = $preview->preview(new ShippingDestination('US', 'CA', '90210'), 4999, 499);
    expect($inside->zoneName)->toBe('California 90')
        ->and($inside->methods[0]['label'])->toBe('Local ground')
        ->and($inside->methods[0]['amount'])->toBe(499)
        ->and($inside->methods[0]['offered'])->toBeTrue()
        ->and($inside->methods[1]['label'])->toBe('Free over fifty')
        ->and($inside->methods[1]['offered'])->toBeFalse();
    $threshold = $preview->preview(new ShippingDestination('US', 'CA', '90210'), 5000, 499);
    expect($threshold->methods[1]['offered'])->toBeTrue()
        ->and($threshold->methods[1]['amount'])->toBe(0);
    $outsidePostal = $preview->preview(new ShippingDestination('US', 'CA', '91001'), 1000, 100);
    expect($outsidePostal->zoneName)->toBe('United States')
        ->and($outsidePostal->methods[0]['amount'])->toBe(799);
    $weightBoundary = $preview->preview(new ShippingDestination('US', 'WA', '98101'), 1000, 500);
    expect($weightBoundary->methods[1]['amount'])->toBe(1200)
        ->and($weightBoundary->methods[1]['offered'])->toBeTrue();
    $weightBelow = $preview->preview(new ShippingDestination('US', 'WA', '98101'), 1000, 499);
    expect($weightBelow->methods[1]['amount'])->toBe(500);
    $missingWeight = $preview->preview(new ShippingDestination('US', 'WA', '98101'), 1000, null);
    expect($missingWeight->methods[1]['offered'])->toBeFalse()
        ->and($missingWeight->methods[1]['reason'])->toBe('missing_measurement');
    expect(fn () => $preview->preview(new ShippingDestination('FR', null, null), 1000, 100))
        ->toThrow(HttpException::class);
});

it('ShippingRules HTTP keeps privileged staff behind two-factor and rejects customers', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/shipping')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/shipping', ['rules' => shippingRulesInput()])->assertForbidden();
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->postJson('/admin/shipping', ['rules' => shippingRulesInput()])
        ->assertUnauthorized();
    $this->get('/')->assertOk();
});

it('ShippingRules HTTP lets authorized staff save and preview rules and denies other roles', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canManageShipping', true));
    $this->get('/admin/shipping')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ShippingRules')
        ->where('rules.origin', null)
        ->where('canWrite', true));
    $this->post('/admin/shipping', ['rules' => shippingRulesInput()])->assertRedirect('/admin/shipping');
    $this->get('/admin/shipping')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ShippingRules')
        ->where('rules.origin.city', 'Los Angeles')
        ->where('rules.zones.0.name', 'United States'));
    $this->post('/admin/shipping/preview', [
        'preview' => [
            'country_code' => 'US',
            'region_code' => 'CA',
            'postal_code' => '90001',
            'physical_subtotal' => 1000,
            'weight_grams' => 250,
        ],
    ])->assertRedirect('/admin/shipping');
    $this->get('/admin/shipping')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ShippingRules')
        ->where('preview.zoneName', 'United States')
        ->where('preview.methods.0.amount', 799));
    foreach (['content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()))
            ->postJson('/admin/shipping', ['rules' => shippingRulesInput()])
            ->assertForbidden();
    }
    $this->get('/')->assertOk();
});

it('ShippingRules HTTP rejects forged shipping saves', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->app->instance('env', 'local');
    $this->postJson('/admin/shipping', ['rules' => shippingRulesInput()])->assertStatus(419);
    $this->app->instance('env', 'testing');
});

it('ShippingRules rejects unauthorized invalid and stale configuration', function (): void {
    $owner = catalogInstalledOwner();
    $denied = $owner->fresh();
    $denied->forceFill(['role' => 'fulfillment', 'permissions' => StaffPermissions::forRole('fulfillment')])->save();
    expect(fn () => app(SaveShippingRules::class)->save($denied, shippingRulesInput()))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveShippingRules::class)->save($owner, shippingRulesInput([
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['type' => 'flat', 'amount' => null])]])],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveShippingRules::class)->save($owner, shippingRulesInput([
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['type' => 'free', 'amount' => null, 'free_min_subtotal' => null])]])],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveShippingRules::class)->save($owner, shippingRulesInput([
        'zones' => [shippingZoneInput([
            'methods' => [shippingMethodInput([
                'type' => 'table', 'amount' => null, 'table_basis' => 'subtotal',
                'table_rows' => [['min' => 0, 'max' => 1000, 'amount' => 400], ['min' => 500, 'max' => null, 'amount' => 800]],
            ])],
        ])],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveShippingRules::class)->save($owner, shippingRulesInput([
        'origin' => shippingOriginInput(['country_code' => 'ZZ']),
    ])))->toThrow(ValidationException::class);
    $first = shippingSavedRules($owner);
    expect(fn () => app(SaveShippingRules::class)->save($owner, shippingRulesInput([
        'origin' => shippingOriginInput(['version' => 99]),
    ])))->toThrow(HttpException::class);
    $second = app(SaveShippingRules::class)->save($owner, shippingRulesInput([
        'origin' => shippingOriginInput(['city' => 'Oakland', 'version' => $first['revision']]),
        'zones' => [shippingZoneInput(['name' => 'US rest'])],
    ]));
    expect($second['revision'])->toBe(2)
        ->and(app(ReadShippingRules::class)->staffRules()['origin']['city'])->toBe('Oakland');
    expect(fn () => app(SaveShippingRules::class)->save($owner, shippingRulesInput([
        'classes' => [['key' => 'general', 'name' => '']],
        'origin' => shippingOriginInput(['version' => 2]),
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveShippingRules::class)->save($owner, shippingRulesInput([
        'classes' => [],
        'origin' => shippingOriginInput(['version' => 2]),
    ])))->toThrow(ValidationException::class);
});
