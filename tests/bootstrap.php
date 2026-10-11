<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$application = require dirname(__DIR__).'/bootstrap/app.php';
if ($application->configurationIsCached()) {
    throw new RuntimeException('Tests refuse cached application configuration. Remove it before running the isolated test suite.');
}
