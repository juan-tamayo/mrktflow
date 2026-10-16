<?php

declare(strict_types=1);

use App\Domain\CheckoutOrders\Actions\RequestRefund;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Support\TestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::verify($app);
if (! isset($argv[1]) || ! Str::isUuid($argv[1]) || config('database.connections.mysql.database') !== 'mrktflow_test') {
    throw new RuntimeException('Refund fixture requires the isolated PHP test database and a UUID.');
}
$input = json_decode((string) file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($input)
    || ! isset($input['staff_id'], $input['order_id'], $input['line_id'], $input['capture_id'], $input['idempotency_key'])
    || ! Str::isUuid((string) $input['staff_id'])
    || ! Str::isUuid((string) $input['order_id'])
    || ! Str::isUuid((string) $input['line_id'])
    || ! Str::isUuid((string) $input['idempotency_key'])) {
    throw new RuntimeException('Invalid refund fixture input.');
}
$staff = StaffAccount::query()->find($input['staff_id']);
if (! $staff instanceof StaffAccount) {
    throw new RuntimeException('Missing refund staff.');
}
config([
    'services.stripe.secret' => 'sk_test_fixture',
    'services.stripe.account' => 'acct_fixture',
    'services.stripe.live' => false,
]);
Http::fake(function (Request $request) use ($input): mixed {
    $posted = $request->data();
    $meta = is_array($posted['metadata'] ?? null) ? $posted['metadata'] : [];
    $refundId = is_string($meta['refund_id'] ?? null) ? (string) $meta['refund_id'] : '00000000-0000-4000-8000-000000000000';
    if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/refunds')) {
        $meta = $refundId !== '00000000-0000-4000-8000-000000000000' ? ['refund_id' => $refundId] : [];

        return Http::response([
            'id' => 're_race_'.$refundId,
            'object' => 'refund',
            'amount' => is_numeric($posted['amount'] ?? null) ? (int) $posted['amount'] : 2475,
            'payment_intent' => (string) $input['capture_id'],
            'status' => 'succeeded',
            'metadata' => $meta,
        ], 200);
    }
});
file_put_contents(base_path('.runtime/refund-'.$argv[1].'.ready'), 'ready', LOCK_EX);
try {
    $result = app(RequestRefund::class)->request($staff, (string) $input['order_id'], [
        'lines' => [['id' => (string) $input['line_id'], 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Race partial refund.',
    ], (string) $input['idempotency_key']);
    echo json_encode(['status' => $result['status']], JSON_THROW_ON_ERROR);
} catch (ValidationException) {
    echo json_encode(['status' => 'exhausted'], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['status' => 'error', 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
