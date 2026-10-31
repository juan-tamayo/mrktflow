<?php

declare(strict_types=1);

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Shipping\Actions\SaveShippingRules;
use App\Domain\Shipping\Contracts\ShippingQuoter;
use App\Domain\Shipping\Data\ShippingDestination;
use App\Domain\Shipping\Data\ShippingQuoteLine;
use App\Domain\Shipping\Data\ShippingQuoteRequest;
use App\Domain\Shipping\Exceptions\ShippingInvalidQuote;
use App\Domain\Shipping\Exceptions\ShippingMissingMeasurement;
use App\Domain\Shipping\Exceptions\ShippingNoService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

function shippingPhysicalLine(string $suffix, int $amount, ?int $grams = 250, string $class = 'general', int $quantity = 1): ShippingQuoteLine
{
    return new ShippingQuoteLine(
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa'.$suffix,
        'physical',
        $class,
        $quantity,
        $amount,
        $grams,
        $grams === null ? null : 100,
        $grams === null ? null : 80,
        $grams === null ? null : 40,
    );
}

function shippingDigitalLine(string $suffix, int $amount): ShippingQuoteLine
{
    return new ShippingQuoteLine(
        'cccccccc-cccc-4ccc-8ccc-cccccccccc'.$suffix,
        'digital',
        'general',
        1,
        $amount,
        null,
        null,
        null,
        null,
    );
}

it('ShippingQuote quotes digital-only carts at zero without an address or method', function (): void {
    catalogInstalledOwner();
    $quote = app(ShippingQuoter::class)->quote(new ShippingQuoteRequest(
        [shippingDigitalLine('01', 1999), shippingDigitalLine('02', 500)],
        null,
        null,
    ));
    expect($quote->digitalOnly)->toBeTrue()
        ->and($quote->requiresDeliveryAddress)->toBeFalse()
        ->and($quote->charge)->toBe(0)
        ->and($quote->eligible)->toBe([])
        ->and($quote->chosen)->toBeNull()
        ->and($quote->taxDestination)->toBeNull()
        ->and($quote->pickupInstructions)->toBeNull()
        ->and($quote->physicalSubtotal)->toBe(0)
        ->and($quote->fingerprint)->toHaveLength(64);
    $this->get('/')->assertOk();
});

it('ShippingQuote ignores digital lines for mixed-cart charges and free thresholds', function (): void {
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [
            shippingZoneInput([
                'methods' => [
                    shippingMethodInput(['label' => 'Ground', 'amount' => 799]),
                    shippingMethodInput([
                        'label' => 'Free over fifty',
                        'type' => 'free',
                        'amount' => null,
                        'free_min_subtotal' => 5000,
                    ]),
                ],
            ]),
        ],
    ]);
    $quoter = app(ShippingQuoter::class);
    $destination = new ShippingDestination('US', 'CA', '90001');
    $mixedBelow = $quoter->quote(new ShippingQuoteRequest(
        [shippingPhysicalLine('01', 4000, 200), shippingDigitalLine('01', 20000)],
        $destination,
        null,
    ));
    expect($mixedBelow->digitalOnly)->toBeFalse()
        ->and($mixedBelow->physicalSubtotal)->toBe(4000)
        ->and($mixedBelow->eligible[0]['label'])->toBe('Ground')
        ->and($mixedBelow->eligible[0]['amount'])->toBe(799)
        ->and($mixedBelow->eligible[0]['offered'])->toBeTrue()
        ->and($mixedBelow->eligible[1]['label'])->toBe('Free over fifty')
        ->and($mixedBelow->eligible[1]['offered'])->toBeFalse()
        ->and($mixedBelow->eligible[1]['reason'])->toBe('below_threshold');
    $mixedAtThreshold = $quoter->quote(new ShippingQuoteRequest(
        [shippingPhysicalLine('01', 5000, 200), shippingDigitalLine('01', 1)],
        $destination,
        null,
    ));
    expect($mixedAtThreshold->physicalSubtotal)->toBe(5000)
        ->and($mixedAtThreshold->eligible[1]['offered'])->toBeTrue()
        ->and($mixedAtThreshold->eligible[1]['amount'])->toBe(0);
    $physicalOnly = $quoter->quote(new ShippingQuoteRequest(
        [shippingPhysicalLine('01', 4000, 200), shippingPhysicalLine('02', 1000, 50)],
        $destination,
        null,
    ));
    expect($physicalOnly->physicalSubtotal)->toBe(5000)
        ->and($physicalOnly->eligible[1]['offered'])->toBeTrue();
});

it('ShippingQuote fails unsupported destinations and mixed classes without a covering method', function (): void {
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'classes' => [
            ['key' => 'general', 'name' => 'General'],
            ['key' => 'fragile', 'name' => 'Fragile'],
            ['key' => 'oversized', 'name' => 'Oversized'],
        ],
        'zones' => [
            shippingZoneInput([
                'methods' => [
                    shippingMethodInput([
                        'label' => 'Fragile only',
                        'amount' => 1200,
                        'included_class_keys' => ['fragile'],
                    ]),
                    shippingMethodInput([
                        'label' => 'Not oversized',
                        'amount' => 900,
                        'excluded_class_keys' => ['oversized'],
                    ]),
                ],
            ]),
        ],
    ]);
    $quoter = app(ShippingQuoter::class);
    expect(fn () => $quoter->quote(new ShippingQuoteRequest(
        [shippingPhysicalLine('01', 1000)],
        new ShippingDestination('FR', null, null),
        null,
    )))->toThrow(ShippingNoService::class);
    expect(fn () => $quoter->quote(new ShippingQuoteRequest(
        [
            shippingPhysicalLine('01', 1000, 100, 'fragile'),
            shippingPhysicalLine('02', 1000, 100, 'oversized'),
        ],
        new ShippingDestination('US', 'CA', '90001'),
        null,
    )))->toThrow(ShippingNoService::class);
    $fragile = $quoter->quote(new ShippingQuoteRequest(
        [shippingPhysicalLine('01', 1000, 100, 'fragile')],
        new ShippingDestination('US', 'CA', '90001'),
        null,
    ));
    expect(array_column(array_filter($fragile->eligible, fn (array $method): bool => $method['offered']), 'label'))
        ->toBe(['Fragile only', 'Not oversized']);
    $general = $quoter->quote(new ShippingQuoteRequest(
        [shippingPhysicalLine('01', 1000, 100, 'general')],
        new ShippingDestination('US', 'CA', '90001'),
        null,
    ));
    expect(array_column(array_filter($general->eligible, fn (array $method): bool => $method['offered']), 'label'))
        ->toBe(['Not oversized']);
    expect(fn () => $quoter->quote(new ShippingQuoteRequest(
        [shippingPhysicalLine('01', 1000, 100, 'unknownx')],
        new ShippingDestination('US', 'CA', '90001'),
        null,
    )))->toThrow(ShippingNoService::class);
});

it('ShippingQuote blocks weight tables without inventing missing grams', function (): void {
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [
            shippingZoneInput([
                'methods' => [
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
    $quoter = app(ShippingQuoter::class);
    $destination = new ShippingDestination('US', 'WA', '98101');
    $known = $quoter->quote(new ShippingQuoteRequest(
        [shippingPhysicalLine('01', 1000, 250, 'general', 2)],
        $destination,
        null,
    ));
    expect($known->eligible[0]['amount'])->toBe(1200)
        ->and($known->eligible[0]['offered'])->toBeTrue();
    expect(fn () => $quoter->quote(new ShippingQuoteRequest(
        [shippingPhysicalLine('01', 1000, null)],
        $destination,
        null,
    )))->toThrow(ShippingMissingMeasurement::class);
    expect(fn () => $quoter->quote(new ShippingQuoteRequest(
        [shippingPhysicalLine('01', 1000, 100), shippingPhysicalLine('02', 500, null)],
        $destination,
        null,
    )))->toThrow(ShippingMissingMeasurement::class);
});

it('ShippingQuote uses pickup location as tax destination without a delivery address', function (): void {
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [
            shippingZoneInput([
                'methods' => [
                    shippingMethodInput(['label' => 'Ground', 'amount' => 799]),
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
    ]);
    $quote = app(ShippingQuoter::class)->quote(new ShippingQuoteRequest(
        [shippingPhysicalLine('01', 2500)],
        null,
        null,
    ));
    expect($quote->eligible)->toHaveCount(1)
        ->and($quote->eligible[0]['label'])->toBe('Warehouse pickup')
        ->and($quote->eligible[0]['type'])->toBe('pickup')
        ->and($quote->eligible[0]['amount'])->toBe(0)
        ->and($quote->eligible[0]['requiresDeliveryAddress'])->toBeFalse()
        ->and($quote->eligible[0]['promisesStock'])->toBeFalse()
        ->and($quote->eligible[0]['pickup']['city'])->toBe('Los Angeles')
        ->and($quote->eligible[0]['pickup']['instructions'])->toBe('Use the dock door after payment.');
    $methodId = $quote->eligible[0]['id'];
    $chosen = app(ShippingQuoter::class)->quote(new ShippingQuoteRequest(
        [shippingPhysicalLine('01', 2500)],
        null,
        $methodId,
    ));
    expect($chosen->chosen['id'])->toBe($methodId)
        ->and($chosen->charge)->toBe(0)
        ->and($chosen->requiresDeliveryAddress)->toBeFalse()
        ->and($chosen->pickupInstructions)->toBe('Use the dock door after payment.')
        ->and($chosen->taxDestination)->toBeInstanceOf(ShippingDestination::class)
        ->and($chosen->taxDestination?->countryCode)->toBe('US')
        ->and($chosen->taxDestination?->regionCode)->toBe('CA')
        ->and($chosen->taxDestination?->postalCode)->toBe('90001');
});

it('ShippingQuote freezes method revision and charge after later rule edits', function (): void {
    $owner = catalogInstalledOwner();
    $firstSave = shippingSavedRules($owner, [
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['label' => 'Ground', 'amount' => 799])]])],
    ]);
    $quoter = app(ShippingQuoter::class);
    $request = new ShippingQuoteRequest(
        [shippingPhysicalLine('01', 1000)],
        new ShippingDestination('US', 'CA', '90001'),
        null,
    );
    $first = $quoter->quote($request);
    expect($first->rulesRevision)->toBe($firstSave['revision'])
        ->and($first->eligible[0]['amount'])->toBe(799)
        ->and($first->charge)->toBe(799);
    $secondSave = app(SaveShippingRules::class)->save($owner, shippingRulesInput([
        'origin' => shippingOriginInput(['version' => $firstSave['revision']]),
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['label' => 'Ground', 'amount' => 999])]])],
    ]));
    expect($first->rulesRevision)->toBe(1)
        ->and($first->eligible[0]['amount'])->toBe(799)
        ->and($first->charge)->toBe(799)
        ->and($first->fingerprint)->toHaveLength(64);
    $second = $quoter->quote($request);
    expect($secondSave['revision'])->toBe(2)
        ->and($second->rulesRevision)->toBe(2)
        ->and($second->eligible[0]['amount'])->toBe(999)
        ->and($second->fingerprint)->not->toBe($first->fingerprint);
});

it('ShippingQuote rejects invalid snapshots and keeps privileged shipping admin behind two-factor', function (): void {
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner);
    expect(fn () => new ShippingQuoteRequest([], new ShippingDestination('US', 'CA', '90001'), null))
        ->toThrow(ShippingInvalidQuote::class);
    expect(fn () => new ShippingQuoteLine('not-a-uuid', 'physical', 'general', 1, 100, 10, 10, 10, 10))
        ->toThrow(ShippingInvalidQuote::class);
    expect(fn () => new ShippingQuoteLine(
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa99',
        'digital',
        'general',
        1,
        100,
        10,
        null,
        null,
        null,
    ))->toThrow(ShippingInvalidQuote::class);
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/shipping')->assertRedirect('/admin/two-factor');
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper-quote@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->postJson('/admin/shipping', ['rules' => shippingRulesInput()])
        ->assertUnauthorized();
    $this->get('/')->assertOk();
});
