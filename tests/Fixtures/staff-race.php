<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\ChangeStaffAccess;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! isset($argv[1]) || ! Str::isUuid($argv[1])) {
    throw new RuntimeException('Staff concurrency fixture requires the guarded test environment and a UUID.');
}
$staff = StaffAccount::query()->findOrFail($argv[1]);
file_put_contents(base_path('.runtime/staff-race-'.$staff->id.'.ready'), 'ready', LOCK_EX);
try {
    app(ChangeStaffAccess::class)->change($staff, $staff->id, ['role' => 'manager', 'is_active' => true, 'version' => 1]);
    echo json_encode(['status' => 200], JSON_THROW_ON_ERROR);
} catch (HttpException $exception) {
    echo json_encode(['status' => $exception->getStatusCode()], JSON_THROW_ON_ERROR);
}
