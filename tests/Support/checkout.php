<?php

declare(strict_types=1);

/** @param array<string, mixed> $overrides */
function checkoutAddress(array $overrides = []): array
{
    return array_replace([
        'recipient' => 'Alex Shopper',
        'line1' => '10 Market Street',
        'line2' => null,
        'city' => 'Los Angeles',
        'region' => 'CA',
        'postal_code' => '90001',
        'country_code' => 'US',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function checkoutQuoteInput(int $revision, array $overrides = []): array
{
    return array_replace([
        'expected_revision' => $revision,
        'email' => 'guest@example.test',
        'coupon' => null,
        'shipping_method_id' => null,
        'billing' => checkoutAddress(),
        'shipping' => checkoutAddress(),
    ], $overrides);
}
