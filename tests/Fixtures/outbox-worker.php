<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/CrashableOutboxConsumer.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('The interruption fixture must run in the guarded test environment.');
}
config(['outbox.consumers' => array_replace(config('outbox.consumers'), ['fixture.worker' => ['fixture.worker' => CrashableOutboxConsumer::class]])]);
exit($app->handleCommand(new ArgvInput));
