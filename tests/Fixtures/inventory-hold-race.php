<?php

declare(strict_types=1);

use App\Domain\Catalog\Contracts\InventoryAllocator;
use App\Domain\Catalog\Data\InventoryHold;
use App\Domain\Catalog\Data\InventoryLine;
use App\Domain\Catalog\Exceptions\CatalogInsufficientStock;
use App\Domain\Platform\Support\TestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::verify($app);
if (! isset($argv[1]) || ! Str::isUuid($argv[1]) || config('database.connections.mysql.database') !== 'mrktflow_test') {
    throw new RuntimeException('Inventory fixture requires the isolated PHP test database and a UUID.');
}
$input = json_decode((string) file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($input)
    || ! isset($input['variant_id'], $input['intent_id'], $input['operation_key'])
    || ! Str::isUuid((string) $input['variant_id'])
    || ! Str::isUuid((string) $input['intent_id'])
    || ! Str::isUuid((string) $input['operation_key'])) {
    throw new RuntimeException('Invalid inventory fixture input.');
}
file_put_contents(base_path('.runtime/inventory-'.$argv[1].'.ready'), 'ready', LOCK_EX);
try {
    app(InventoryAllocator::class)->hold(new InventoryHold(
        (string) $input['intent_id'],
        (string) $input['operation_key'],
        now()->addMinutes(30)->toDateTimeImmutable(),
        [new InventoryLine((string) $input['variant_id'], 1)],
    ));
    echo json_encode(['status' => 'held'], JSON_THROW_ON_ERROR);
} catch (CatalogInsufficientStock) {
    echo json_encode(['status' => 'insufficient'], JSON_THROW_ON_ERROR);
}
