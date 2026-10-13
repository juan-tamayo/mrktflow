<?php

declare(strict_types=1);

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Pricing\Actions\SaveTaxRules;
use App\Domain\Pricing\Data\Money;
use App\Domain\Pricing\Data\PricedLine;
use App\Domain\Pricing\Data\TaxableLine;
use App\Domain\Pricing\Data\TaxContext;
use App\Domain\Pricing\Data\TaxDestination;

/** @param array<string, mixed> $overrides */
function pricingTaxMatchInput(array $overrides = []): array
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
function pricingTaxRuleInput(array $overrides = []): array
{
    $defaults = [
        'name' => 'United States standard',
        'class_key' => 'standard',
        'status' => 'active',
        'rate_numerator' => 10,
        'rate_denominator' => 100,
        'match' => pricingTaxMatchInput(),
    ];
    if (isset($overrides['match']) && is_array($overrides['match'])) {
        $overrides['match'] = array_replace($defaults['match'], $overrides['match']);
    }

    return array_replace($defaults, $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pricingTaxRulesInput(array $overrides = []): array
{
    $defaults = [
        'price_mode' => 'exclusive',
        'shipping_tax_class_key' => 'standard',
        'version' => null,
        'classes' => [
            ['key' => 'standard', 'name' => 'Standard'],
        ],
        'rules' => [pricingTaxRuleInput()],
    ];

    return array_replace($defaults, $overrides);
}

/** @param array<string, mixed> $overrides */
function pricingSavedTaxRules(StaffAccount $owner, array $overrides = []): array
{
    return app(SaveTaxRules::class)->save($owner, pricingTaxRulesInput($overrides));
}

function pricingTaxUsd(int $amount): Money
{
    return Money::of($amount, 'USD', 2);
}

function pricingTaxLine(string $id, int $quantity, Money $regular): PricedLine
{
    return new PricedLine(
        $id,
        str_replace('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb', $id),
        $quantity,
        $regular,
    );
}

function pricingTaxDestination(string $country = 'US', ?string $region = 'CA', ?string $postal = '90210'): TaxDestination
{
    return new TaxDestination($country, $region, $postal);
}

/**
 * @param  list<TaxableLine>  $lines
 */
function pricingTaxContext(array $lines, string $mode = 'exclusive', ?string $shippingClass = 'standard'): TaxContext
{
    $needsShipping = false;
    $needsBilling = false;
    foreach ($lines as $line) {
        if ($line->fulfillment === 'physical') {
            $needsShipping = true;
        }
        if ($line->fulfillment === 'digital') {
            $needsBilling = true;
        }
    }

    return new TaxContext(
        $lines,
        $mode,
        $needsBilling ? pricingTaxDestination() : null,
        $needsShipping ? pricingTaxDestination() : null,
        $shippingClass,
    );
}

function pricingTaxable(string $lineId, string $fulfillment = 'physical', string $class = 'standard'): TaxableLine
{
    return new TaxableLine($lineId, $class, $fulfillment);
}

function pricingTaxUuid(string $suffix): string
{
    return 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa'.$suffix;
}
