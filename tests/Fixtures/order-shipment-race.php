<?php

declare(strict_types=1);

use App\Domain\Fulfillment\Actions\CreateShipment;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Support\TestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::verify($app);
if (! isset($argv[1]) || ! Str::isUuid($argv[1]) || config('database.connections.mysql.database') !== 'mrktflow_test') {
    throw new RuntimeException('Shipment fixture requires the isolated PHP test database and a UUID.');
}
$input = json_decode((string) file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($input)
    || ! isset($input['staff_id'], $input['order_id'], $input['line_id'], $input['quantity'], $input['idempotency_key'])
    || ! Str::isUuid((string) $input['staff_id'])
    || ! Str::isUuid((string) $input['order_id'])
    || ! Str::isUuid((string) $input['line_id'])
    || ! Str::isUuid((string) $input['idempotency_key'])
    || ! is_int($input['quantity'])) {
    throw new RuntimeException('Invalid shipment fixture input.');
}
$staff = StaffAccount::query()->find($input['staff_id']);
if (! $staff instanceof StaffAccount) {
    throw new RuntimeException('Missing shipment staff.');
}
file_put_contents(base_path('.runtime/shipment-'.$argv[1].'.ready'), 'ready', LOCK_EX);
try {
    $result = app(CreateShipment::class)->create($staff, (string) $input['order_id'], [
        'lines' => [['id' => (string) $input['line_id'], 'quantity' => $input['quantity']]],
    ], (string) $input['idempotency_key']);
    echo json_encode(['status' => 'created', 'id' => $result['id']], JSON_THROW_ON_ERROR);
} catch (ValidationException) {
    echo json_encode(['status' => 'exhausted'], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['status' => 'error', 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
