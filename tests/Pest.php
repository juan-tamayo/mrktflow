<?php

declare(strict_types=1);
use Tests\TestCase;

require __DIR__.'/Support/catalog.php';
require __DIR__.'/Support/pricing.php';

pest()->extend(TestCase::class)->in('Feature');
