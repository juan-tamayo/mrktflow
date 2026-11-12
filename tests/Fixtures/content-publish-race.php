<?php

declare(strict_types=1);

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Support\TestDatabaseGuard;
use App\Domain\ThemesContent\Actions\SaveContent;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::verify($app);
if (! isset($argv[1], $argv[2], $argv[3]) || ! Str::isUuid($argv[1]) || ! Str::isUuid($argv[2])) {
    throw new RuntimeException('Content race fixture requires the isolated test database, a staff UUID and an article UUID.');
}
$actor = StaffAccount::query()->findOrFail($argv[1]);
$articleId = $argv[2];
$title = $argv[3];
if (! in_array($title, ['Draft from editor one', 'Draft from editor two'], true)) {
    throw new RuntimeException('Content race fixture requires a known editor title.');
}
$readyKey = str_replace(' ', '-', $title);
file_put_contents(base_path('.runtime/content-'.$readyKey.'.ready'), 'ready', LOCK_EX);
try {
    app(SaveContent::class)->saveArticle($actor, [
        'id' => $articleId,
        'version' => 1,
        'title' => $title,
        'slug' => 'desk-lamp-guide',
        'excerpt' => 'A later unpublished rewrite.',
        'status' => 'draft',
    ]);
    echo json_encode(['status' => 200, 'title' => $title], JSON_THROW_ON_ERROR);
} catch (HttpException $exception) {
    echo json_encode(['status' => $exception->getStatusCode(), 'title' => $title], JSON_THROW_ON_ERROR);
}
