<?php

declare(strict_types=1);

use App\Domain\Platform\Support\TestDatabaseGuard;
use App\Domain\Pricing\Actions\HoldPromotionBudget;
use App\Domain\Pricing\Data\PromotionBudgetHold;
use App\Domain\Pricing\Exceptions\PricingCouponExhausted;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::verify($app);
if (! isset($argv[1]) || ! Str::isUuid($argv[1]) || config('database.connections.mysql.database') !== 'mrktflow_test') {
    throw new RuntimeException('Promotion budget fixture requires the isolated PHP test database and a UUID.');
}
$input = json_decode((string) file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($input)
    || ! isset($input['campaign_id'], $input['revision'], $input['intent_id'], $input['operation_key'], $input['amount'])
    || ! Str::isUuid((string) $input['campaign_id'])
    || ! Str::isUuid((string) $input['intent_id'])
    || ! Str::isUuid((string) $input['operation_key'])) {
    throw new RuntimeException('Invalid promotion budget fixture input.');
}
$customer = isset($input['customer_key']) && is_string($input['customer_key']) ? (string) $input['customer_key'] : null;
$first = ($input['first_customer'] ?? false) === true;
file_put_contents(base_path('.runtime/promotion-budget-'.$argv[1].'.ready'), 'ready', LOCK_EX);
try {
    app(HoldPromotionBudget::class)->hold(new PromotionBudgetHold(
        (string) $input['intent_id'],
        (string) $input['operation_key'],
        (string) $input['campaign_id'],
        (int) $input['revision'],
        (int) $input['amount'],
        isset($input['coupon_id']) && is_string($input['coupon_id']) ? (string) $input['coupon_id'] : null,
        $customer,
        $first,
        now()->addMinutes(30)->toDateTimeImmutable(),
    ));
    echo json_encode(['status' => 'held'], JSON_THROW_ON_ERROR);
} catch (PricingCouponExhausted) {
    echo json_encode(['status' => 'exhausted'], JSON_THROW_ON_ERROR);
}
