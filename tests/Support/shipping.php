<?php

declare(strict_types=1);

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Shipping\Actions\SaveShippingRules;

/** @param array<string, mixed> $overrides */
function shippingOriginInput(array $overrides = []): array
{
    return array_replace([
        'country_code' => 'US',
        'region_code' => 'CA',
        'postal_code' => '90001',
        'city' => 'Los Angeles',
        'street' => '100 Warehouse Ave',
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function shippingMatchInput(array $overrides = []): array
{
    return array_replace([
        'country_code' => 'US',
        'region_code' => null,
        'postal_pattern' => null,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function shippingMethodInput(array $overrides = []): array
{
    return array_replace([
        'label' => 'Ground',
        'type' => 'flat',
        'amount' => 799,
        'free_min_subtotal' => null,
        'table_basis' => null,
        'table_rows' => [],
        'included_class_keys' => [],
        'excluded_class_keys' => [],
        'pickup' => null,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function shippingZoneInput(array $overrides = []): array
{
    return array_replace([
        'name' => 'United States',
        'priority' => 20,
        'status' => 'active',
        'matches' => [shippingMatchInput()],
        'methods' => [shippingMethodInput()],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function shippingRulesInput(array $overrides = []): array
{
    $defaults = [
        'origin' => shippingOriginInput(),
        'classes' => [
            ['key' => 'general', 'name' => 'General'],
        ],
        'zones' => [shippingZoneInput()],
    ];
    if (isset($overrides['origin']) && is_array($overrides['origin'])) {
        $overrides['origin'] = array_replace($defaults['origin'], $overrides['origin']);
    }

    return array_replace($defaults, $overrides);
}

/** @param array<string, mixed> $overrides */
function shippingSavedRules(StaffAccount $owner, array $overrides = []): array
{
    return app(SaveShippingRules::class)->save($owner, shippingRulesInput($overrides));
}
