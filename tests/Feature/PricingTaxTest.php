<?php

declare(strict_types=1);

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Pricing\Actions\CalculatePrice;
use App\Domain\Pricing\Actions\CalculateTax;
use App\Domain\Pricing\Actions\PreviewTax;
use App\Domain\Pricing\Actions\ReadTaxRules;
use App\Domain\Pricing\Actions\SaveTaxRules;
use App\Domain\Pricing\Contracts\PriceCalculator;
use App\Domain\Pricing\Data\MerchandiseDiscount;
use App\Domain\Pricing\Data\Money;
use App\Domain\Pricing\Data\PriceRequest;
use App\Domain\Pricing\Data\TaxContext;
use App\Domain\Pricing\Data\TaxDestination;
use App\Domain\Pricing\Exceptions\PricingInvalidQuote;
use App\Domain\Pricing\Exceptions\PricingTaxCoverageMissing;
use App\Domain\Pricing\Exceptions\PricingTaxRuleConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(DatabaseMigrations::class);
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

it('PricingTax lets merchants configure classes destination rates and exclusive price mode', function (): void {
    $owner = catalogInstalledOwner();
    $saved = app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'classes' => [
            ['key' => 'standard', 'name' => 'Standard'],
            ['key' => 'reduced', 'name' => 'Reduced'],
        ],
        'rules' => [
            pricingTaxRuleInput([
                'name' => 'California standard',
                'match' => pricingTaxMatchInput(['region_code' => 'CA', 'postal_pattern' => '90*']),
                'rate_numerator' => 975,
                'rate_denominator' => 10000,
            ]),
            pricingTaxRuleInput([
                'name' => 'United States standard',
                'rate_numerator' => 0,
                'rate_denominator' => 100,
            ]),
            pricingTaxRuleInput([
                'name' => 'United States reduced',
                'class_key' => 'reduced',
                'rate_numerator' => 5,
            ]),
        ],
    ]));
    expect($saved['price_mode'])->toBe('exclusive')
        ->and($saved['revision'])->toBe(1)
        ->and(DB::table('tax_classes')->where('class_key', 'standard')->exists())->toBeTrue()
        ->and(DB::table('tax_classes')->where('class_key', 'reduced')->exists())->toBeTrue()
        ->and(DB::table('tax_rules')->count())->toBe(3)
        ->and((int) DB::table('tax_rules')->where('name', 'United States standard')->value('rate_numerator'))->toBe(0);
    $loaded = app(ReadTaxRules::class)->staffRules();
    expect($loaded['price_mode'])->toBe('exclusive')
        ->and($loaded['shipping_tax_class_key'])->toBe('standard')
        ->and($loaded['rules'][0]['name'])->toBe('California standard');
    $this->get('/')->assertStatus(503);
});

it('PricingTax rejects equal-specificity overlapping destination rules', function (): void {
    $owner = catalogInstalledOwner();
    expect(fn () => app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'rules' => [
            pricingTaxRuleInput(['name' => 'US catch-all']),
            pricingTaxRuleInput(['name' => 'US duplicate']),
        ],
    ])))->toThrow(PricingTaxRuleConflict::class);
    expect(fn () => app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'rules' => [
            pricingTaxRuleInput([
                'name' => 'Prefix',
                'match' => pricingTaxMatchInput(['postal_pattern' => '90*']),
            ]),
            pricingTaxRuleInput([
                'name' => 'Exact',
                'match' => pricingTaxMatchInput(['postal_pattern' => '90210']),
            ]),
        ],
    ])))->toThrow(PricingTaxRuleConflict::class);
    expect(fn () => app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'rules' => [
            pricingTaxRuleInput([
                'name' => 'Low range',
                'match' => pricingTaxMatchInput(['postal_pattern' => '10000-20000']),
            ]),
            pricingTaxRuleInput([
                'name' => 'High range',
                'match' => pricingTaxMatchInput(['postal_pattern' => '15000-25000']),
            ]),
        ],
    ])))->toThrow(PricingTaxRuleConflict::class);
    expect(fn () => app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'rules' => [
            pricingTaxRuleInput([
                'name' => 'Wide prefix',
                'match' => pricingTaxMatchInput(['postal_pattern' => '9*']),
            ]),
            pricingTaxRuleInput([
                'name' => 'Narrow prefix',
                'match' => pricingTaxMatchInput(['postal_pattern' => '90*']),
            ]),
        ],
    ])))->toThrow(PricingTaxRuleConflict::class);
    $ok = app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'rules' => [
            pricingTaxRuleInput([
                'name' => 'California',
                'match' => pricingTaxMatchInput(['region_code' => 'CA']),
            ]),
            pricingTaxRuleInput([
                'name' => 'New York',
                'match' => pricingTaxMatchInput(['region_code' => 'NY']),
            ]),
            pricingTaxRuleInput([
                'name' => 'United States fallback',
                'rate_numerator' => 0,
            ]),
            pricingTaxRuleInput([
                'name' => 'Draft duplicate',
                'status' => 'draft',
            ]),
        ],
    ]));
    expect($ok['revision'])->toBe(1);
});

it('PricingTax calculates documented exclusive mixed-cart totals and fails closed without coverage', function (): void {
    $owner = catalogInstalledOwner();
    pricingSavedTaxRules($owner);
    $physicalId = pricingTaxUuid('1');
    $digitalId = pricingTaxUuid('2');
    $physical = pricingTaxLine($physicalId, 2, pricingTaxUsd(1000));
    $digital = pricingTaxLine($digitalId, 1, pricingTaxUsd(500));
    $untaxed = (new CalculatePrice)->quote(new PriceRequest(
        [$physical, $digital],
        new DateTimeImmutable('2026-10-13T00:00:00Z'),
        MerchandiseDiscount::percentage(10, 100),
        pricingTaxUsd(300),
    ));
    expect($untaxed->discountedMerchandise->amount)->toBe(2250)
        ->and($untaxed->tax->amount)->toBe(0)
        ->and($untaxed->grandTotal->amount)->toBe(2550);
    $context = new TaxContext(
        [
            pricingTaxable($physicalId, 'physical'),
            pricingTaxable($digitalId, 'digital'),
        ],
        'exclusive',
        pricingTaxDestination(),
        pricingTaxDestination(),
        'standard',
    );
    $taxed = app(CalculateTax::class)->apply($untaxed, $context);
    expect($taxed->tax->amount)->toBe(255)
        ->and($taxed->grandTotal->amount)->toBe(2805)
        ->and($taxed->priceMode)->toBe('exclusive')
        ->and($taxed->shipping->amount)->toBe(300);
    $composed = app(PriceCalculator::class)->quote(new PriceRequest(
        [$physical, $digital],
        new DateTimeImmutable('2026-10-13T00:00:00Z'),
        MerchandiseDiscount::percentage(10, 100),
        pricingTaxUsd(300),
        $context,
    ));
    expect($composed->tax->amount)->toBe(255)->and($composed->grandTotal->amount)->toBe(2805);
    $preview = app(PreviewTax::class)->preview([
        'cart' => 'mixed',
        'billing_country_code' => 'US',
        'billing_region_code' => 'CA',
        'billing_postal_code' => '90210',
        'shipping_country_code' => 'US',
        'shipping_region_code' => 'CA',
        'shipping_postal_code' => '90210',
        'physical_amount' => 1800,
        'digital_amount' => 450,
        'shipping_amount' => 300,
        'physical_class_key' => 'standard',
        'digital_class_key' => 'standard',
    ]);
    expect($preview['ok'])->toBeTrue()
        ->and($preview['tax'])->toBe(255)
        ->and($preview['grandTotal'])->toBe(2805);
    expect(fn () => app(CalculateTax::class)->apply($untaxed, new TaxContext(
        [pricingTaxable($physicalId, 'physical'), pricingTaxable($digitalId, 'digital')],
        'exclusive',
        new TaxDestination('FR', null, null),
        pricingTaxDestination(),
        'standard',
    )))->toThrow(PricingTaxCoverageMissing::class);
    expect(fn () => app(CalculateTax::class)->apply($untaxed, new TaxContext(
        [pricingTaxable($physicalId, 'physical')],
        'exclusive',
        null,
        null,
        'standard',
    )))->toThrow(PricingTaxCoverageMissing::class);
    $this->get('/')->assertStatus(503);
});

it('PricingTax calculates inclusive totals and uses physical shipping versus digital billing destinations', function (): void {
    $owner = catalogInstalledOwner();
    app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'price_mode' => 'inclusive',
        'rules' => [
            pricingTaxRuleInput(['name' => 'US ten percent']),
        ],
    ]));
    $a = pricingTaxLine(pricingTaxUuid('1'), 2, pricingTaxUsd(1000));
    $b = pricingTaxLine(pricingTaxUuid('2'), 1, pricingTaxUsd(200));
    $untaxed = (new CalculatePrice)->quote(new PriceRequest(
        [$a, $b],
        new DateTimeImmutable('2026-10-13T00:00:00Z'),
        null,
        pricingTaxUsd(330),
    ));
    expect($untaxed->discountedMerchandise->amount)->toBe(2200);
    $inclusive = app(CalculateTax::class)->apply($untaxed, new TaxContext(
        [
            pricingTaxable(pricingTaxUuid('1'), 'physical'),
            pricingTaxable(pricingTaxUuid('2'), 'digital'),
        ],
        'inclusive',
        pricingTaxDestination(),
        pricingTaxDestination(),
        'standard',
    ));
    expect($inclusive->tax->amount)->toBe(230)
        ->and($inclusive->grandTotal->amount)->toBe(2530)
        ->and($inclusive->priceMode)->toBe('inclusive');
    app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'price_mode' => 'exclusive',
        'version' => 1,
        'rules' => [
            pricingTaxRuleInput([
                'name' => 'California physical',
                'match' => pricingTaxMatchInput(['region_code' => 'CA']),
                'rate_numerator' => 10,
            ]),
            pricingTaxRuleInput([
                'name' => 'New York digital',
                'match' => pricingTaxMatchInput(['region_code' => 'NY']),
                'rate_numerator' => 0,
            ]),
        ],
    ]));
    $physicalId = pricingTaxUuid('3');
    $digitalId = pricingTaxUuid('4');
    $split = (new CalculatePrice)->quote(new PriceRequest(
        [pricingTaxLine($physicalId, 1, pricingTaxUsd(1000)), pricingTaxLine($digitalId, 1, pricingTaxUsd(1000))],
        new DateTimeImmutable('2026-10-13T00:00:00Z'),
    ));
    $splitTax = app(CalculateTax::class)->apply($split, new TaxContext(
        [pricingTaxable($physicalId, 'physical'), pricingTaxable($digitalId, 'digital')],
        'exclusive',
        new TaxDestination('US', 'NY', '10001'),
        new TaxDestination('US', 'CA', '90210'),
        'standard',
    ));
    expect($splitTax->tax->amount)->toBe(100)
        ->and($splitTax->grandTotal->amount)->toBe(2100);
    $odd = (new CalculatePrice)->quote(new PriceRequest(
        [pricingTaxLine(pricingTaxUuid('5'), 1, pricingTaxUsd(15))],
        new DateTimeImmutable('2026-10-13T00:00:00Z'),
    ));
    $oddTax = app(CalculateTax::class)->apply($odd, pricingTaxContext([pricingTaxable(pricingTaxUuid('5'))]));
    expect($oddTax->tax->amount)->toBe(2);
    $yen = Money::of(1000, 'JPY', 0);
    $yenQuote = (new CalculatePrice)->quote(new PriceRequest(
        [pricingTaxLine(pricingTaxUuid('6'), 1, $yen)],
        new DateTimeImmutable('2026-10-13T00:00:00Z'),
    ));
    $yenTax = app(CalculateTax::class)->apply($yenQuote, pricingTaxContext([pricingTaxable(pricingTaxUuid('6'))]));
    expect($yenTax->tax->amount)->toBe(100)->and($yenTax->grandTotal->formatted())->toBe('JPY 1100');
    $kwd = Money::of(1000, 'KWD', 3);
    $kwdQuote = (new CalculatePrice)->quote(new PriceRequest(
        [pricingTaxLine(pricingTaxUuid('7'), 1, $kwd)],
        new DateTimeImmutable('2026-10-13T00:00:00Z'),
    ));
    $kwdTax = app(CalculateTax::class)->apply($kwdQuote, pricingTaxContext([pricingTaxable(pricingTaxUuid('7'))]));
    expect($kwdTax->tax->amount)->toBe(100);
    $this->get('/')->assertStatus(503);
});

it('PricingTax HTTP keeps privileged staff behind two-factor and rejects customers', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/taxes')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/taxes', ['rules' => pricingTaxRulesInput()])->assertForbidden();
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->postJson('/admin/taxes', ['rules' => pricingTaxRulesInput()])
        ->assertUnauthorized();
    $this->get('/')->assertStatus(503);
});

it('PricingTax HTTP lets authorized staff save and preview rules and denies other roles', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canManagePricing', true));
    $this->get('/admin/taxes')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Taxes')
        ->where('rules.price_mode', 'exclusive')
        ->where('canWrite', true));
    $this->post('/admin/taxes', ['rules' => pricingTaxRulesInput()])->assertRedirect('/admin/taxes');
    $this->get('/admin/taxes')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Taxes')
        ->where('rules.rules.0.name', 'United States standard')
        ->where('rules.price_mode', 'exclusive'));
    $this->post('/admin/taxes/preview', [
        'preview' => [
            'cart' => 'physical',
            'shipping_country_code' => 'US',
            'shipping_region_code' => 'CA',
            'shipping_postal_code' => '90210',
            'physical_amount' => 1000,
            'digital_amount' => 0,
            'shipping_amount' => 300,
            'physical_class_key' => 'standard',
            'digital_class_key' => 'standard',
        ],
    ])->assertRedirect('/admin/taxes');
    $this->get('/admin/taxes')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Taxes')
        ->where('preview.ok', true)
        ->where('preview.tax', 130)
        ->where('preview.grandTotal', 1430));
    $this->post('/admin/taxes/preview', [
        'preview' => [
            'cart' => 'digital',
            'billing_country_code' => 'FR',
            'physical_amount' => 0,
            'digital_amount' => 500,
            'shipping_amount' => 0,
            'physical_class_key' => 'standard',
            'digital_class_key' => 'standard',
        ],
    ])->assertRedirect('/admin/taxes');
    $this->get('/admin/taxes')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Taxes')
        ->where('preview.ok', false));
    foreach (['content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()))
            ->postJson('/admin/taxes', ['rules' => pricingTaxRulesInput()])
            ->assertForbidden();
    }
    $this->get('/')->assertStatus(503);
});

it('PricingTax HTTP rejects forged tax saves', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->app->instance('env', 'local');
    $this->postJson('/admin/taxes', ['rules' => pricingTaxRulesInput()])->assertStatus(419);
    $this->app->instance('env', 'testing');
});

it('PricingTax rejects unauthorized invalid and stale configuration', function (): void {
    $owner = catalogInstalledOwner();
    $denied = $owner->fresh();
    $denied->forceFill(['role' => 'fulfillment', 'permissions' => StaffPermissions::forRole('fulfillment')])->save();
    expect(fn () => app(SaveTaxRules::class)->save($denied, pricingTaxRulesInput()))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'classes' => [],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'price_mode' => 'compound',
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'rules' => [pricingTaxRuleInput(['rate_numerator' => 150, 'rate_denominator' => 100])],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'shipping_tax_class_key' => 'missing',
    ])))->toThrow(ValidationException::class);
    $first = pricingSavedTaxRules($owner);
    expect(fn () => app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'version' => 99,
    ])))->toThrow(HttpException::class);
    $second = app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'version' => $first['revision'],
        'price_mode' => 'inclusive',
        'rules' => [pricingTaxRuleInput(['name' => 'Inclusive US'])],
    ]));
    expect($second['revision'])->toBe(2)
        ->and(app(ReadTaxRules::class)->staffRules()['price_mode'])->toBe('inclusive');
    expect(fn () => app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'version' => 2,
        'classes' => [['key' => 'standard', 'name' => '']],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'version' => 2,
        'rules' => [pricingTaxRuleInput(['match' => pricingTaxMatchInput(['postal_pattern' => '@@'])])],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'version' => 2,
        'rules' => [pricingTaxRuleInput(['class_key' => 'missing'])],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'version' => 2,
        'rules' => [pricingTaxRuleInput(['match' => pricingTaxMatchInput(['region_code' => '!!!'])])],
    ])))->toThrow(ValidationException::class);
    $withExtra = app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'version' => 2,
        'classes' => [
            ['key' => 'standard', 'name' => 'Standard'],
            ['key' => 'reduced', 'name' => 'Reduced'],
        ],
        'rules' => [pricingTaxRuleInput(['name' => 'US after extra class'])],
    ]));
    expect($withExtra['revision'])->toBe(3);
    $removed = app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput([
        'version' => 3,
        'rules' => [pricingTaxRuleInput(['name' => 'US after removing extra'])],
    ]));
    expect($removed['revision'])->toBe(4)
        ->and(DB::table('tax_classes')->where('class_key', 'reduced')->exists())->toBeFalse();
    expect(fn () => app(PreviewTax::class)->preview(['cart' => 'bundle', 'physical_amount' => 1, 'digital_amount' => 0, 'shipping_amount' => 0]))
        ->toThrow(PricingInvalidQuote::class);
});
