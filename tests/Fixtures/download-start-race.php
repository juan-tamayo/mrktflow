<?php

declare(strict_types=1);

use App\Domain\Fulfillment\Actions\AuthorizeDownload;
use App\Domain\Fulfillment\Data\DownloadPrincipal;
use App\Domain\Platform\Support\TestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::verify($app);
if (! isset($argv[1]) || ! Str::isUuid($argv[1]) || config('database.connections.mysql.database') !== 'mrktflow_test') {
    throw new RuntimeException('Download fixture requires the isolated PHP test database and a UUID.');
}
$input = json_decode((string) file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($input)
    || ! array_key_exists('customer_id', $input) || ! array_key_exists('order_id', $input)
    || ! isset($input['grant_id'], $input['token'], $input['session_id'], $input['now'])
    || ($input['customer_id'] !== null && ! Str::isUuid((string) $input['customer_id']))
    || ($input['order_id'] !== null && ! Str::isUuid((string) $input['order_id']))
    || ! Str::isUuid((string) $input['grant_id'])
    || ! is_string($input['token']) || ! is_string($input['session_id'])) {
    throw new RuntimeException('Invalid download fixture input.');
}
// The parent test freezes time with Carbon::setTestNow, which cannot
// cross the process boundary; the frozen instant arrives as input so the
// five-minute token window is evaluated on the same clock.
Carbon::setTestNow(Carbon::parse((string) $input['now']));
$principal = new DownloadPrincipal(
    $input['customer_id'] !== null ? (string) $input['customer_id'] : null,
    $input['order_id'] !== null ? (string) $input['order_id'] : null,
    $input['session_id'],
);
file_put_contents(base_path('.runtime/download-'.$argv[1].'.ready'), 'ready', LOCK_EX);
try {
    $result = app(AuthorizeDownload::class)->start($principal, (string) $input['grant_id'], $input['token']);
    echo json_encode(['status' => 'started', 'transfer_id' => $result['transfer_id']], JSON_THROW_ON_ERROR);
} catch (HttpException $exception) {
    echo json_encode(['status' => 'denied', 'code' => $exception->getStatusCode()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['status' => 'error', 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
