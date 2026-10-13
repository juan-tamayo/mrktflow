<?php

declare(strict_types=1);

use App\Domain\Platform\Support\TestDatabaseGuard;
use App\Domain\Pricing\Actions\HoldCoupon;
use App\Domain\Pricing\Data\CouponHold;
use App\Domain\Pricing\Exceptions\PricingCouponExhausted;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::verify($app);
if (! isset($argv[1]) || ! Str::isUuid($argv[1]) || config('database.connections.mysql.database') !== 'mrktflow_test') {
    throw new RuntimeException('Coupon fixture requires the isolated PHP test database and a UUID.');
}
$input = json_decode((string) file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($input)
    || ! isset($input['coupon_id'], $input['revision'], $input['intent_id'], $input['operation_key'], $input['customer_key'])
    || ! Str::isUuid((string) $input['coupon_id'])
    || ! Str::isUuid((string) $input['intent_id'])
    || ! Str::isUuid((string) $input['operation_key'])
    || ! Str::isUuid((string) $input['customer_key'])) {
    throw new RuntimeException('Invalid coupon fixture input.');
}
file_put_contents(base_path('.runtime/coupon-'.$argv[1].'.ready'), 'ready', LOCK_EX);
try {
    app(HoldCoupon::class)->hold(new CouponHold(
        (string) $input['intent_id'],
        (string) $input['operation_key'],
        (string) $input['coupon_id'],
        (int) $input['revision'],
        (string) $input['customer_key'],
        now()->addMinutes(30)->toDateTimeImmutable(),
    ));
    echo json_encode(['status' => 'held'], JSON_THROW_ON_ERROR);
} catch (PricingCouponExhausted) {
    echo json_encode(['status' => 'exhausted'], JSON_THROW_ON_ERROR);
}
