<?php

declare(strict_types=1);
use Tests\TestCase;

require __DIR__.'/Support/catalog.php';

pest()->extend(TestCase::class)->in('Feature');
