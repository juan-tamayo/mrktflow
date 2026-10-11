<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArgvInput;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('The media interruption fixture requires the guarded test environment.');
}
config([
    'filesystems.disks.local.root' => base_path('.runtime/media-worker-private'),
    'filesystems.disks.public.root' => base_path('.runtime/media-worker-public'),
]);
$real = Storage::disk('public');
$proxy = Mockery::mock($real);
$proxy->shouldReceive('move')->andReturnUsing(function (string $from, string $to) use ($real): bool {
    $result = $real->move($from, $to);
    if (file_exists(base_path('.runtime/media-worker-pause-request'))) {
        file_put_contents(base_path('.runtime/media-worker-paused'), 'renamed-before-commit');
        while (true) {
            usleep(20000);
        }
    }

    return $result;
});
Storage::set('public', $proxy);
exit($app->handleCommand(new ArgvInput));
