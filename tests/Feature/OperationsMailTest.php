<?php

declare(strict_types=1);

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Operations\Actions\DeliverNotification;
use App\Domain\Operations\Actions\PreviewNotification;
use App\Domain\Operations\Actions\PublishNotification;
use App\Domain\Operations\Actions\QueueNotification;
use App\Domain\Platform\Actions\UpdateDeliveryPreference;
use App\Jobs\SendNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
    $this->freezeTime();
    config(['app.url' => 'http://127.0.0.1:8000']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function operationsMailOwner(): StaffAccount
{
    return catalogInstalledOwner();
}

function operationsMailContact(string $email, string $purpose): string
{
    $prefs = app(UpdateDeliveryPreference::class);
    $id = $prefs->ensureContact($email);
    $prefs->markVerified($id, 'double_opt_in');
    $prefs->grant($id, $purpose, 'preference_center', 'account_verified');

    return $id;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function operationsMailInput(array $overrides = []): array
{
    return array_replace([
        'intent_key' => 'receipt:paid:'.(string) Str::uuid(),
        'template' => 'order-receipt',
        'recipient' => 'buyer@example.test',
        'kind' => 'event',
        'facts' => [
            'heading' => 'Order receipt',
            'body' => 'Order MF-1001. Total USD 28.05.',
            'order_number' => 'MF-1001',
        ],
    ], $overrides);
}

it('OperationsMail deduplicates delivery intent and sends transactional mail despite marketing suppression', function (): void {
    operationsMailOwner();
    $prefs = app(UpdateDeliveryPreference::class);
    $contactId = $prefs->ensureContact('buyer@example.test');
    $prefs->markVerified($contactId, 'checkout_receipt');
    $prefs->suppress($contactId, 'global', 'opt_out', 'preference_center');
    $input = operationsMailInput();
    $first = app(QueueNotification::class)->queue($input);
    $second = app(QueueNotification::class)->queue($input);
    expect($second['id'])->toBe($first['id']);
    expect(DB::table('notification_deliveries')->where('intent_key', $input['intent_key'])->count())->toBe(1);
    $row = DB::table('notification_deliveries')->where('id', $first['id'])->first();
    expect($row->status)->toBe('pending')->and($row->policy_class)->toBe('transactional');
    $payload = json_decode(Crypt::decryptString($row->payload), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['email'])->toBe('buyer@example.test');
    expect($row->payload)->not->toContain('buyer@example.test');
    expect(DB::table('jobs')->where('queue', 'transactional-mail')->count())->toBe(1);
    expect(DB::table('jobs')->where('queue', 'transactional-mail')->value('payload'))
        ->not->toContain($first['id'])->not->toContain('buyer@example.test');
    expect(serialize(new SendNotification($first['id'])))->not->toContain('buyer@example.test');
    app(DeliverNotification::class)->deliver($first['id']);
    $messages = Mail::mailer()->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    $email = $messages->first()->getOriginalMessage();
    expect($email->getFrom()[0]->getAddress())->toBe('store@example.test');
    expect($email->getHtmlBody())->toContain('Order MF-1001')->toContain('USD 28.05');
    expect(DB::table('notification_deliveries')->where('id', $first['id'])->first())
        ->status->toBe('delivered')->payload->toBeNull();
    app(DeliverNotification::class)->deliver($first['id']);
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('OperationsMail holds marketing behind purpose grants and cancels unsubscribe-before-retry', function (): void {
    operationsMailOwner();
    $email = 'subscriber@example.test';
    $contactId = operationsMailContact($email, 'newsletter');
    $input = operationsMailInput([
        'intent_key' => 'newsletter:welcome:'.$contactId,
        'template' => 'newsletter',
        'recipient' => $email,
        'facts' => ['heading' => 'Studio notes', 'body' => 'This month in the workshop.'],
    ]);
    $blocked = app(QueueNotification::class)->queue(array_replace($input, [
        'intent_key' => 'newsletter:blocked:'.$contactId,
        'recipient' => 'nobody@example.test',
    ]));
    app(DeliverNotification::class)->deliver($blocked['id']);
    expect(DB::table('notification_deliveries')->where('id', $blocked['id'])->first())
        ->status->toBe('cancelled')->last_error_code->toBe('delivery_not_allowed');
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);

    $queued = app(QueueNotification::class)->queue($input);
    expect(DB::table('notification_deliveries')->where('id', $queued['id'])->first()->policy_class)->toBe('marketing');
    expect(DB::table('jobs')->where('queue', 'marketing')->count())->toBeGreaterThanOrEqual(1);
    Mail::shouldReceive('mailer')->once()->andThrow(new RuntimeException('PRIVATE_TRANSPORT_SENTINEL'));
    app(DeliverNotification::class)->deliver($queued['id']);
    $failed = DB::table('notification_deliveries')->where('id', $queued['id'])->first();
    expect($failed->status)->toBe('pending')->and($failed->last_error_code)->toBe('transport_failed')->and($failed->attempts)->toBe(1);
    expect(json_encode($failed))->not->toContain('PRIVATE_TRANSPORT_SENTINEL');
});

it('OperationsMail cancels a retry when the contact unsubscribes after a transport outage', function (): void {
    operationsMailOwner();
    $email = 'later@example.test';
    $contactId = operationsMailContact($email, 'newsletter');
    $queued = app(QueueNotification::class)->queue(operationsMailInput([
        'intent_key' => 'newsletter:retry:'.$contactId,
        'template' => 'newsletter',
        'recipient' => $email,
        'facts' => ['heading' => 'Studio notes', 'body' => 'This month in the workshop.'],
    ]));
    DB::table('notification_deliveries')->where('id', $queued['id'])->update([
        'attempts' => 1, 'last_error_code' => 'transport_failed', 'queued_until' => null, 'updated_at' => now(),
    ]);
    app(UpdateDeliveryPreference::class)->revoke($contactId, 'newsletter', 'unsubscribe_link');
    app(DeliverNotification::class)->deliver($queued['id']);
    expect(DB::table('notification_deliveries')->where('id', $queued['id'])->first())
        ->status->toBe('cancelled')->last_error_code->toBe('delivery_not_allowed');
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('OperationsMail recovers from a transport outage without writing order rows or claiming exactly-once SMTP', function (): void {
    operationsMailOwner();
    $before = (int) DB::table('orders')->count();
    $queued = app(QueueNotification::class)->queue(operationsMailInput([
        'intent_key' => 'receipt:paid:'.(string) Str::uuid(),
        'recipient' => 'paid@example.test',
        'facts' => ['heading' => 'Order receipt', 'body' => 'Order MF-OUTAGE. Total USD 25.00.', 'order_number' => 'MF-OUTAGE'],
    ]));
    Mail::shouldReceive('mailer')->once()->andThrow(new RuntimeException('smtp-timeout'));
    app(DeliverNotification::class)->deliver($queued['id']);
    expect(DB::table('orders')->count())->toBe($before);
    expect(DB::table('notification_deliveries')->where('id', $queued['id'])->first()->status)->toBe('pending');
});

it('OperationsMail delivers a previously failed intent after the transport recovers', function (): void {
    operationsMailOwner();
    $before = (int) DB::table('orders')->count();
    $queued = app(QueueNotification::class)->queue(operationsMailInput([
        'intent_key' => 'receipt:paid:'.(string) Str::uuid(),
        'recipient' => 'paid@example.test',
        'facts' => ['heading' => 'Order receipt', 'body' => 'Order MF-OUTAGE. Total USD 25.00.', 'order_number' => 'MF-OUTAGE'],
    ]));
    DB::table('notification_deliveries')->where('id', $queued['id'])->update([
        'attempts' => 1, 'last_error_code' => 'transport_failed', 'queued_until' => null, 'updated_at' => now(),
    ]);
    app(DeliverNotification::class)->deliver($queued['id']);
    expect(DB::table('notification_deliveries')->where('id', $queued['id'])->first()->status)->toBe('delivered');
    expect(DB::table('orders')->count())->toBe($before);
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('OperationsMail previews templates without sending and test-sends only to the acting staff mailbox', function (): void {
    $owner = operationsMailOwner();
    $html = app(PreviewNotification::class)->html('order-receipt');
    expect($html)->toContain('Order receipt')->toContain('Example preview')->not->toContain('<script>');
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/email')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('EmailSettings')
        ->where('sender.email', 'store@example.test')
        ->where('exactlyOnce', false)
        ->where('disclaimer', 'Transactional delivery is at-least-once: a crash after the transport accepts a message can send a duplicate. This store does not promise exactly-once SMTP.')
        ->has('templates')
        ->where('preview.template', 'order-receipt'));
    $this->from('/admin/email')->post('/admin/email/test', [
        'template' => 'newsletter',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertRedirect('/admin/email');
    $test = DB::table('notification_deliveries')->where('kind', 'test')->first();
    expect($test->recipient)->toBe($owner->email)->and($test->template)->toBe('newsletter');
    app(DeliverNotification::class)->deliver($test->id);
    $sent = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    expect($sent->getTo()[0]->getAddress())->toBe($owner->email);
    expect($sent->getSubject())->toContain('Test:');
    $this->from('/admin/email')->post('/admin/email/test', [
        'template' => 'order-receipt',
        'recipient' => 'stranger@example.test',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertRedirect('/admin/email')->assertSessionHasErrors();
    expect(DB::table('notification_deliveries')->where('kind', 'test')->count())->toBe(1);
});

it('OperationsMail retries transport failure with bounded attempts and no private error text', function (): void {
    operationsMailOwner();
    $queued = app(QueueNotification::class)->queue(operationsMailInput());
    Mail::shouldReceive('mailer')->andThrow(new RuntimeException('PRIVATE_TRANSPORT_SENTINEL'));
    for ($attempt = 1; $attempt <= 6; $attempt++) {
        app(DeliverNotification::class)->deliver($queued['id']);
        $row = DB::table('notification_deliveries')->where('id', $queued['id'])->first();
        expect($row->attempts)->toBe($attempt);
        expect($row->last_error_code)->toBe('transport_failed');
        expect(json_encode($row))->not->toContain('PRIVATE_TRANSPORT_SENTINEL');
        $this->travel(901)->seconds();
    }
    expect($row->status)->toBe('failed');
});

it('OperationsMail lists visible failures and retries after repair', function (): void {
    $owner = operationsMailOwner();
    $queued = app(QueueNotification::class)->queue(operationsMailInput());
    DB::table('notification_deliveries')->where('id', $queued['id'])->update([
        'status' => 'failed', 'attempts' => 6, 'last_error_code' => 'transport_failed',
        'queued_until' => null, 'claim_id' => null, 'lease_until' => null, 'updated_at' => now(),
    ]);
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/notifications')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('NotificationFailures')
        ->where('failures.0.id', $queued['id'])
        ->where('failures.0.errorCode', 'transport_failed')
        ->where('exactlyOnce', false)
        ->where('disclaimer', 'Transactional delivery is at-least-once: a crash after the transport accepts a message can send a duplicate. This store does not promise exactly-once SMTP.'));
    $this->from('/admin/notifications')->post('/admin/notifications/'.$queued['id'].'/retry')
        ->assertRedirect('/admin/notifications');
    expect(DB::table('notification_deliveries')->where('id', $queued['id'])->first()->status)->toBe('pending');
    app(DeliverNotification::class)->deliver($queued['id']);
    expect(DB::table('notification_deliveries')->where('id', $queued['id'])->first()->status)->toBe('delivered');
});

it('OperationsMail refuses the log transport, forbids in-transaction sends, and keeps GET / closed', function (): void {
    operationsMailOwner();
    $queued = app(QueueNotification::class)->queue(operationsMailInput());
    config(['mail.default' => 'log']);
    app(DeliverNotification::class)->deliver($queued['id']);
    expect(DB::table('notification_deliveries')->where('id', $queued['id'])->first())
        ->status->toBe('pending')->attempts->toBe(0)->last_error_code->toBe('mail_configuration_unavailable');
    config(['mail.default' => 'array']);
    DB::beginTransaction();
    try {
        expect(fn () => app(DeliverNotification::class)->deliver($queued['id']))->toThrow(LogicException::class);
    } finally {
        DB::rollBack();
    }
    $this->get('/')->assertStatus(503);
});

it('OperationsMail publishes through the database outbox worker against the array transport', function (): void {
    operationsMailOwner();
    $queued = app(QueueNotification::class)->queue(operationsMailInput());
    $this->artisan('queue:work', ['connection' => 'database-outbox', '--queue' => 'transactional-mail', '--once' => true, '--tries' => 1, '--timeout' => 60])->assertSuccessful();
    expect(DB::table('notification_deliveries')->where('id', $queued['id'])->first()->status)->toBe('delivered');
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
    config(['queue.connections.database-outbox.table' => 'missing_operations_queue']);
    app()->forgetInstance('queue');
    Queue::clearResolvedInstance('queue');
    app(QueueNotification::class)->queue(operationsMailInput(['intent_key' => 'receipt:paid:'.(string) Str::uuid()]));
    $orphaned = DB::table('notification_deliveries')->where('status', 'pending')->whereNull('queued_until')->first();
    expect($orphaned)->not->toBeNull();
    config(['queue.connections.database-outbox.table' => 'jobs']);
    app()->forgetInstance('queue');
    Queue::clearResolvedInstance('queue');
    expect(app(PublishNotification::class)->recover(1))->toBe(1);
});

it('OperationsMail HTTP keeps privileged staff behind two-factor and rejects customers', function (): void {
    $owner = operationsMailOwner();
    $this->get('/admin/email')->assertRedirect('/admin/login');
    $this->postJson('/admin/email/test', ['template' => 'order-receipt'])->assertUnauthorized();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->getJson('/admin/email')->assertUnauthorized();
    auth('customer')->logout();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version]);
    $this->get('/admin/email')->assertRedirect('/admin/two-factor');
    $this->post('/admin/email/test', ['template' => 'order-receipt'])->assertRedirect('/admin/two-factor');
    $editor = new StaffAccount;
    $editor->forceFill([
        'name' => 'Editor', 'email' => 'editor@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'content_editor', 'permissions' => StaffPermissions::forRole('content_editor'),
        'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor))
        ->get('/admin/email')->assertForbidden();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->from('/admin/email')->postJson('/admin/email/test', ['template' => 'order-receipt'])->assertUnprocessable();
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canManageEmail', true));
    $this->get('/')->assertStatus(503);
});
