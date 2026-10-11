<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\ResetCustomerPassword;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! isset($argv[1]) || ! Str::isUuid($argv[1])) {
    throw new RuntimeException('Recovery concurrency fixture requires guarded testing and a UUID.');
}
$input = json_decode(file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);
file_put_contents(base_path('.runtime/customer-reset-'.$argv[1].'.ready'), 'ready', LOCK_EX);
try {
    app(ResetCustomerPassword::class)->reset($input['token'], ['password' => $input['password'], 'password_confirmation' => $input['password']]);
    echo json_encode(['status' => 200], JSON_THROW_ON_ERROR);
} catch (HttpException $exception) {
    echo json_encode(['status' => $exception->getStatusCode()], JSON_THROW_ON_ERROR);
}
