<?php

declare(strict_types=1);
use Tests\TestCase;

require __DIR__.'/Support/catalog.php';
require __DIR__.'/Support/pricing.php';
require __DIR__.'/Support/shipping.php';
require __DIR__.'/Support/tax.php';
require __DIR__.'/Support/checkout.php';

pest()->extend(TestCase::class)->in('Feature');
