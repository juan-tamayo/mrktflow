<?php

declare(strict_types=1);

use App\Domain\AiAuthoring\Actions\ReserveUsage;
use App\Domain\AiAuthoring\Data\UsageHold;
use App\Domain\AiAuthoring\Exceptions\AiBudgetExhausted;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Support\TestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::verify($app);
if (! isset($argv[1]) || ! Str::isUuid($argv[1]) || config('database.connections.mysql.database') !== 'mrktflow_test') {
    throw new RuntimeException('AI budget fixture requires the isolated PHP test database and a UUID.');
}
$input = json_decode((string) file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($input)
    || ! isset($input['staff_id'], $input['intent_id'], $input['operation_key'], $input['estimated_tokens'])
    || ! Str::isUuid((string) $input['staff_id'])
    || ! Str::isUuid((string) $input['intent_id'])
    || ! Str::isUuid((string) $input['operation_key'])
    || ! is_int($input['estimated_tokens'])) {
    throw new RuntimeException('Invalid AI budget fixture input.');
}
file_put_contents(base_path('.runtime/ai-budget-'.$argv[1].'.ready'), 'ready', LOCK_EX);
$staff = StaffAccount::query()->findOrFail((string) $input['staff_id']);
try {
    app(ReserveUsage::class)->reserve($staff, new UsageHold(
        (string) $input['intent_id'],
        (string) $input['operation_key'],
        (string) $input['staff_id'],
        (int) $input['estimated_tokens'],
        now()->addMinutes(30)->toDateTimeImmutable(),
    ));
    echo json_encode(['status' => 'reserved'], JSON_THROW_ON_ERROR);
} catch (AiBudgetExhausted) {
    echo json_encode(['status' => 'exhausted'], JSON_THROW_ON_ERROR);
}
