<?php

declare(strict_types=1);

use App\Application\Identity\RequestCustomerChallengeMail;
use App\Domain\Identity\Actions\DeliverCustomerChallengeMail;
use App\Domain\Identity\Actions\QueueCustomerChallengeMail;
use App\Domain\Identity\Actions\RegisterCustomer;
use App\Jobs\SendCustomerChallengeMail;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fixtures\CustomerRegistration;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    DB::table('installations')->where('id', 1)->update(['status' => 'complete', 'completed_at' => now()]);
    DB::table('store_settings')->insert(['id' => 1, 'version' => 1, 'values' => file_get_contents(base_path('tests/Fixtures/store-settings.json')), 'created_at' => now(), 'updated_at' => now()]);
    $this->freezeTime();
});

it('IdentityCustomerMail atomically stores an encrypted credential message and queues only an encrypted ID', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->verification($customer);
    $delivery = DB::table('customer_challenge_deliveries')->first();
    expect($delivery->status)->toBe('pending');
    $payload = json_decode(Crypt::decryptString($delivery->payload), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['email'])->toBe($customer->email);
    expect($delivery->payload)->not->toContain($customer->email)->not->toContain($payload['token']);
    expect(DB::table('jobs')->where('queue', 'identity-mail')->count())->toBe(1);
    expect(DB::table('jobs')->where('queue', 'identity-mail')->value('payload'))->not->toContain($delivery->id)->not->toContain($payload['token'])->not->toContain($customer->email);
    expect(serialize(new SendCustomerChallengeMail($delivery->id)))->not->toContain($payload['token'])->not->toContain($customer->email);
    expect(app(QueueCustomerChallengeMail::class)->publish($delivery->id))->toBe(0);
});

it('IdentityCustomerMail does not create or publish a message if its outer transaction rolls back', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    DB::beginTransaction();
    app(RequestCustomerChallengeMail::class)->verification($customer);
    expect(DB::table('customer_challenge_deliveries')->count())->toBe(1);
    expect(DB::table('jobs')->where('queue', 'identity-mail')->count())->toBe(0);
    DB::rollBack();
    expect(DB::table('customer_challenges')->count())->toBe(0);
    expect(DB::table('customer_challenge_deliveries')->count())->toBe(0);
    expect(DB::table('jobs')->where('queue', 'identity-mail')->count())->toBe(0);
});

it('IdentityCustomerMail survives publication failure and recovers a bounded pending batch', function (): void {
    config(['queue.connections.database-outbox.table' => 'missing_identity_queue']);
    app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->passwordReset('shopper@example.test');
    $delivery = DB::table('customer_challenge_deliveries')->first();
    expect($delivery->status)->toBe('pending');
    expect($delivery->queued_until)->toBeNull();
    config(['queue.connections.database-outbox.table' => 'jobs']);
    app()->forgetInstance('queue');
    Queue::clearResolvedInstance('queue');
    expect(app(QueueCustomerChallengeMail::class)->recover(1))->toBe(1);
    expect(DB::table('jobs')->where('queue', 'identity-mail')->count())->toBe(1);
});

it('IdentityCustomerMail sends with a configured base URL outside transactions and retires credential payload after acknowledgement', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->verification($customer);
    $delivery = DB::table('customer_challenge_deliveries')->first();
    $payload = json_decode(Crypt::decryptString($delivery->payload), true, flags: JSON_THROW_ON_ERROR);
    app(DeliverCustomerChallengeMail::class)->deliver($delivery->id);
    $messages = Mail::mailer()->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    $email = $messages->first()->getOriginalMessage();
    expect($email->getHtmlBody())->toContain(rtrim(config('app.url'), '/').'/account/verify#'.$payload['token']);
    expect($email->getFrom()[0]->getAddress())->toBe('store@example.test');
    expect(DB::table('customer_challenge_deliveries')->where('id', $delivery->id)->first())->status->toBe('delivered')->payload->toBeNull();
    app(DeliverCustomerChallengeMail::class)->deliver($delivery->id);
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('IdentityCustomerMail cancels a superseded message before dispatch', function (): void {
    app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->passwordReset('shopper@example.test');
    $old = DB::table('customer_challenge_deliveries')->first();
    app(RequestCustomerChallengeMail::class)->passwordReset('shopper@example.test');
    app(DeliverCustomerChallengeMail::class)->deliver($old->id);
    expect(DB::table('customer_challenge_deliveries')->where('id', $old->id)->first())->status->toBe('cancelled')->payload->toBeNull();
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('IdentityCustomerMail refuses the log transport without burning send attempts or leaking credentials', function (): void {
    app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->passwordReset('shopper@example.test');
    $delivery = DB::table('customer_challenge_deliveries')->first();
    config(['mail.default' => 'log']);
    app(DeliverCustomerChallengeMail::class)->deliver($delivery->id);
    expect(DB::table('customer_challenge_deliveries')->where('id', $delivery->id)->first())->status->toBe('pending')->attempts->toBe(0)->last_error_code->toBe('mail_configuration_unavailable');
});

it('IdentityCustomerMail retries transport failure with bounded attempts and no private error text', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->verification($customer);
    $delivery = DB::table('customer_challenge_deliveries')->first();
    Mail::shouldReceive('mailer')->andThrow(new RuntimeException('PRIVATE_TRANSPORT_SENTINEL'));
    for ($attempt = 1; $attempt <= 6; $attempt++) {
        app(DeliverCustomerChallengeMail::class)->deliver($delivery->id);
        $row = DB::table('customer_challenge_deliveries')->where('id', $delivery->id)->first();
        expect($row->attempts)->toBe($attempt);
        expect($row->last_error_code)->toBe('transport_failed');
        expect(json_encode($row))->not->toContain('PRIVATE_TRANSPORT_SENTINEL');
        $this->travel(901)->seconds();
    }
    expect($row->status)->toBe('failed');
});

it('IdentityCustomerMail excludes active leases and recovers an abandoned sending lease', function (): void {
    app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->passwordReset('shopper@example.test');
    $delivery = DB::table('customer_challenge_deliveries')->first();
    DB::table('customer_challenge_deliveries')->where('id', $delivery->id)->update(['status' => 'sending', 'claim_id' => (string) Str::uuid(), 'lease_until' => now()->addSeconds(120), 'attempts' => 1]);
    app(DeliverCustomerChallengeMail::class)->deliver($delivery->id);
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
    $this->travel(121)->seconds();
    expect(app(QueueCustomerChallengeMail::class)->recover(1))->toBe(1);
    app(DeliverCustomerChallengeMail::class)->deliver($delivery->id);
    expect(DB::table('customer_challenge_deliveries')->where('id', $delivery->id)->first())->status->toBe('delivered')->attempts->toBe(2);
});

it('IdentityCustomerMail purges expired encrypted payloads even after terminal transport failure', function (): void {
    app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->passwordReset('shopper@example.test');
    $delivery = DB::table('customer_challenge_deliveries')->first();
    DB::table('customer_challenge_deliveries')->where('id', $delivery->id)->update(['status' => 'failed']);
    $this->travel(61)->minutes();
    app(QueueCustomerChallengeMail::class)->recover(1);
    expect(DB::table('customer_challenge_deliveries')->where('id', $delivery->id)->first())->status->toBe('cancelled')->payload->toBeNull();
});

it('IdentityCustomerMail forbids network delivery inside a database transaction', function (): void {
    DB::beginTransaction();
    try {
        expect(fn () => app(DeliverCustomerChallengeMail::class)->deliver((string) Str::uuid()))->toThrow(LogicException::class);
    } finally {
        DB::rollBack();
    }
});

it('IdentityCustomerMail runs the real database queue worker against the array transport', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->verification($customer);
    $this->artisan('queue:work', ['connection' => 'database-outbox', '--queue' => 'identity-mail', '--once' => true, '--tries' => 1, '--timeout' => 60])->assertSuccessful();
    expect(DB::table('customer_challenge_deliveries')->first())->status->toBe('delivered')->payload->toBeNull();
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
    expect(DB::table('jobs')->where('queue', 'identity-mail')->count())->toBe(0);
});

it('IdentityCustomerMail reports private counts and permits only explicit unexpired failed-delivery retry', function (): void {
    app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->passwordReset('shopper@example.test');
    $delivery = DB::table('customer_challenge_deliveries')->first();
    DB::table('customer_challenge_deliveries')->where('id', $delivery->id)->update(['status' => 'failed', 'last_error_code' => 'transport_failed', 'attempts' => 6]);
    $this->artisan('identity:mail-health')->expectsOutput('pending: 0')->expectsOutput('failed: 1')->expectsOutput('configuration_blocked: 0')->assertFailed();
    $this->artisan('identity:mail-retry', ['delivery' => $delivery->id])->assertSuccessful();
    expect(DB::table('customer_challenge_deliveries')->where('id', $delivery->id)->first())->status->toBe('pending')->attempts->toBe(0);
    $this->artisan('identity:mail-retry', ['delivery' => $delivery->id])->assertFailed();
});

it('IdentityCustomerMail refuses unsafe transport and link configuration before opening a transport', function (array $overrides): void {
    app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->passwordReset('shopper@example.test');
    $delivery = DB::table('customer_challenge_deliveries')->first();
    config($overrides);
    Mail::shouldReceive('mailer')->never();
    app(DeliverCustomerChallengeMail::class)->deliver($delivery->id);
    expect(DB::table('customer_challenge_deliveries')->where('id', $delivery->id)->first())->status->toBe('pending')->attempts->toBe(0)->last_error_code->toBe('mail_configuration_unavailable');
})->with([
    'TLS disabled' => [['mail.default' => 'smtp', 'mail.mailers.smtp.require_tls' => false]],
    'peer validation disabled' => [['mail.default' => 'smtp', 'mail.mailers.smtp.verify_peer' => false]],
    'URL transport override' => [['mail.default' => 'smtp', 'mail.mailers.smtp.url' => 'smtp://localhost?verify_peer=0']],
    'timeout unbounded' => [['mail.default' => 'smtp', 'mail.mailers.smtp.timeout' => 60]],
    'public HTTP link' => [['app.url' => 'http://store.example.test']],
    'link credentials' => [['app.url' => 'https://private:secret@store.example.test']],
    'link query' => [['app.url' => 'https://store.example.test?token=private']],
]);

it('IdentityCustomerMail refuses corrupt or differently bound encrypted credential payloads', function (string $mode): void {
    app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->passwordReset('shopper@example.test');
    $delivery = DB::table('customer_challenge_deliveries')->first();
    $payload = json_decode(Crypt::decryptString($delivery->payload), true, flags: JSON_THROW_ON_ERROR);
    $replacement = match ($mode) {
        'corrupt' => 'invalid-ciphertext',
        'recipient' => Crypt::encryptString(json_encode(['email' => 'other@example.test', 'token' => $payload['token']], JSON_THROW_ON_ERROR)),
        'token' => Crypt::encryptString(json_encode(['email' => $payload['email'], 'token' => str_repeat('a', 64)], JSON_THROW_ON_ERROR)),
    };
    DB::table('customer_challenge_deliveries')->where('id', $delivery->id)->update(['payload' => $replacement]);
    Mail::shouldReceive('mailer')->never();
    app(DeliverCustomerChallengeMail::class)->deliver($delivery->id);
    expect(DB::table('customer_challenge_deliveries')->where('id', $delivery->id)->first())->status->toBe('failed')->attempts->toBe(0)->last_error_code->toBe('message_invalid');
})->with(['corrupt', 'recipient', 'token']);

it('IdentityCustomerMail refuses operator retry after credential expiry', function (): void {
    app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->passwordReset('shopper@example.test');
    $delivery = DB::table('customer_challenge_deliveries')->first();
    DB::table('customer_challenge_deliveries')->where('id', $delivery->id)->update(['status' => 'failed', 'attempts' => 6]);
    $this->travel(61)->minutes();
    $this->artisan('identity:mail-retry', ['delivery' => $delivery->id])->assertFailed();
    expect(DB::table('customer_challenge_deliveries')->where('id', $delivery->id)->first())->status->toBe('failed')->attempts->toBe(6);
});
