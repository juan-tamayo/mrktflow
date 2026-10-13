<?php

declare(strict_types=1);

use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Platform\Support\TestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::verify($app);
if (! isset($argv[1]) || ! Str::isUuid($argv[1]) || config('database.connections.mysql.database') !== 'mrktflow_test') {
    throw new RuntimeException('Checkout intent fixture requires the isolated PHP test database and a UUID.');
}
$input = json_decode((string) file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($input)
    || ! isset($input['guest_token'], $input['quote_id'], $input['idempotency_key'])
    || ! is_string($input['guest_token'])
    || ! Str::isUuid((string) $input['quote_id'])
    || ! Str::isUuid((string) $input['idempotency_key'])) {
    throw new RuntimeException('Invalid checkout intent fixture input.');
}
Carbon::setTestNow(Carbon::parse('2026-10-13T18:00:00Z')->utc());
file_put_contents(base_path('.runtime/checkout-intent-'.$argv[1].'.ready'), 'ready', LOCK_EX);
$intent = app(CreateCheckoutIntent::class)->create(
    CartOwner::guest((string) $input['guest_token']),
    (string) $input['quote_id'],
    (string) $input['idempotency_key'],
);
echo json_encode(['order_id' => $intent['order_id'], 'id' => $intent['id']], JSON_THROW_ON_ERROR);
