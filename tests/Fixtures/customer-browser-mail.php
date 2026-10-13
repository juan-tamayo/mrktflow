<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\DeliverCustomerChallengeMail;
use App\Domain\Platform\Support\TestDatabaseGuard;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$root = dirname(__DIR__, 2);
$environment = Dotenv::parse(file_get_contents($root.'/.env.browser'));
foreach ($environment as $key => $value) {
    if (! is_string($value)) {
        throw new RuntimeException('Explicit browser fixture environment values required.');
    }
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne('SELECT DATABASE() AS db, CURRENT_USER() AS account');
if ($identity === null) {
    fwrite(STDERR, "The guarded browser fixture could not identify its database account.\n");
    exit(1);
}
try {
    TestDatabaseGuard::assertBrowserMailFixture(
        $app->environment(),
        (string) $identity->db,
        (string) $identity->account,
        (string) config('mail.default'),
    );
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
$purpose = trim(stream_get_contents(STDIN));
if (! in_array($purpose, ['verify_email', 'reset_password'], true)) {
    throw new RuntimeException('Unsupported fixture mail purpose.');
}
$row = DB::table('customer_challenge_deliveries')
    ->join('customer_challenges', 'customer_challenges.id', '=', 'customer_challenge_deliveries.challenge_id')
    ->join('customer_accounts', 'customer_accounts.id', '=', 'customer_challenges.customer_id')
    ->where('customer_accounts.email', 'owner@example.test')->where('purpose', $purpose)->whereNull('revoked_at')->whereNull('consumed_at')->whereNotNull('token_digest')
    ->first(['customer_challenge_deliveries.id']);
if ($row === null) {
    throw new RuntimeException('No current customer fixture message.');
}
app(DeliverCustomerChallengeMail::class)->deliver($row->id);
$messages = Mail::mailer()->getSymfonyTransport()->messages();
if ($messages->count() !== 1 || ! preg_match('~/account/(?:verify|reset-password)#([a-f0-9]{64})~', $messages->first()->getOriginalMessage()->getHtmlBody(), $matches)) {
    throw new RuntimeException('Expected one accepted customer fixture email.');
}
echo $matches[1];
