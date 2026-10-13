<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

use InvalidArgumentException;

final readonly class TaxDestination
{
    public string $countryCode;

    public ?string $regionCode;

    public ?string $postalCode;

    public function __construct(string $countryCode, ?string $regionCode, ?string $postalCode)
    {
        $country = strtoupper(trim($countryCode));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new InvalidArgumentException('Destination country is invalid.');
        }
        $region = $regionCode === null || $regionCode === '' ? null : strtoupper(trim($regionCode));
        if ($region !== null && preg_match('/^[A-Z0-9-]{1,16}$/', $region) !== 1) {
            throw new InvalidArgumentException('Destination region is invalid.');
        }
        $postal = $postalCode === null || $postalCode === '' ? null : strtoupper(preg_replace('/\s+/', '', $postalCode) ?? '');
        if ($postal !== null && preg_match('/^[A-Z0-9-]{1,16}$/', $postal) !== 1) {
            throw new InvalidArgumentException('Destination postal code is invalid.');
        }
        $this->countryCode = $country;
        $this->regionCode = $region;
        $this->postalCode = $postal;
    }
}
