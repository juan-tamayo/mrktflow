<?php

declare(strict_types=1);

use App\Domain\Platform\Support\TestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::verify($app);
if (! isset($argv[1]) || ! Str::isUuid($argv[1]) || config('database.connections.mysql.database') !== 'mrktflow_test') {
    throw new RuntimeException('Reservation fixture requires the isolated PHP test database and a UUID.');
}
$input = json_decode(file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($input) || array_keys($input) !== ['rollback'] || ! is_bool($input['rollback'])) {
    throw new RuntimeException('Invalid reservation fixture input.');
}
$connection = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents(base_path('.runtime/checkout-'.$argv[1].'.ready'), 'ready', LOCK_EX);
DB::beginTransaction();
try {
    $stock = DB::table('spike_stock')->where('id', 1)->lockForUpdate()->first();
    if ($stock === null) {
        throw new RuntimeException('Synthetic stock fixture missing.');
    }
    $status = 'sold_out';
    if ($stock->available > 0) {
        DB::table('spike_stock')->where('id', 1)->decrement('available');
        DB::table('spike_reservations')->insert(['attempt_id' => $argv[1], 'quantity' => 1]);
        $status = 'reserved';
    }
    if ($input['rollback']) {
        DB::rollBack();
        $status = 'rolled_back';
    } else {
        DB::commit();
    }
    echo json_encode(['status' => $status, 'connection' => $connection], JSON_THROW_ON_ERROR);
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
