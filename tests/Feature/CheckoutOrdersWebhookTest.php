<?php

declare(strict_types=1);

use App\Domain\CheckoutOrders\Actions\AcceptProviderEvent;
use App\Domain\Identity\Models\CustomerAccount;
use App\Http\Controllers\Webhooks\StripeController;
use App\Jobs\ProcessPaymentEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-13T23:00:00Z')->utc());
    config([
        'app.url' => 'http://127.0.0.1:8000',
        'services.stripe.secret' => 'sk_test_fixture',
        'services.stripe.account' => 'acct_fixture',
        'services.stripe.live' => false,
        'services.stripe.webhook_secret' => 'whsec_test_fixture',
    ]);
    Queue::fake();
});
afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array{payload: string, signature: string, event_id: string}
 */
function signedStripeEvent(array $overrides = []): array
{
    $eventId = is_string($overrides['id'] ?? null) ? (string) $overrides['id'] : 'evt_test_inbox_1';
    $payload = json_encode(array_replace([
        'id' => $eventId,
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'livemode' => false,
        'created' => 1760396400,
        'data' => [
            'object' => [
                'id' => 'cs_test_inbox',
                'object' => 'checkout.session',
                'customer_email' => 'shopper@example.test',
                'client_reference_id' => '14397058-5ad8-4f45-9bbf-b87153dca0ba',
                'amount_total' => 2805,
                'currency' => 'usd',
            ],
        ],
    ], $overrides), JSON_THROW_ON_ERROR);
    $timestamp = (string) Carbon::now()->timestamp;
    $secret = (string) config('services.stripe.webhook_secret');
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

    return ['payload' => $payload, 'signature' => $signature, 'event_id' => $eventId];
}

function postStripeWebhook(array $signed, array $headers = [])
{
    return test()->call(
        'POST',
        '/webhooks/stripe',
        [],
        [],
        [],
        array_replace([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signed['signature'],
        ], $headers),
        $signed['payload'],
    );
}

it('CheckoutOrdersWebhook persists a verified event before 2xx and deduplicates replay', function (): void {
    $this->get('/')->assertStatus(503);
    $signed = signedStripeEvent();
    postStripeWebhook($signed)->assertNoContent();
    postStripeWebhook($signed)->assertNoContent();
    expect(DB::table('provider_events')->count())->toBe(1);
    $row = DB::table('provider_events')->first();
    expect($row->provider)->toBe('stripe')
        ->and($row->account_ref)->toBe('acct_fixture')
        ->and($row->environment)->toBe('test')
        ->and($row->event_id)->toBe('evt_test_inbox_1')
        ->and($row->event_type)->toBe('checkout.session.completed')
        ->and($row->processing_state)->toBe('received')
        ->and($row->payload)->not->toContain('shopper@example.test')
        ->and($row->payload)->not->toContain('checkout.session.completed');
    $decoded = json_decode(Crypt::decryptString($row->payload), true);
    expect($decoded['id'])->toBe('evt_test_inbox_1')
        ->and($decoded['data']['object']['customer_email'])->toBe('shopper@example.test');
    Queue::assertPushed(ProcessPaymentEvent::class, function (ProcessPaymentEvent $job) use ($row): bool {
        return $job->eventId === $row->id && $job->queue === 'payments';
    });
    Queue::assertPushed(ProcessPaymentEvent::class, 2);
});

it('CheckoutOrdersWebhook rejects a wrong signature or environment without storing an event', function (): void {
    $valid = signedStripeEvent();
    postStripeWebhook($valid, ['HTTP_STRIPE_SIGNATURE' => 't='.Carbon::now()->timestamp.',v1='.str_repeat('a', 64)])
        ->assertStatus(400);
    postStripeWebhook(signedStripeEvent(['livemode' => true]))->assertStatus(400);
    postStripeWebhook(signedStripeEvent(['account' => 'acct_other']))->assertStatus(400);
    postStripeWebhook($valid, ['HTTP_STRIPE_ACCOUNT' => 'acct_other'])->assertStatus(400);
    postStripeWebhook(['payload' => $valid['payload'], 'signature' => '', 'event_id' => $valid['event_id']])
        ->assertStatus(400);
    $stale = signedStripeEvent();
    $staleTime = (string) (Carbon::now()->timestamp - 301);
    $stale['signature'] = 't='.$staleTime.',v1='.hash_hmac('sha256', $staleTime.'.'.$stale['payload'], (string) config('services.stripe.webhook_secret'));
    postStripeWebhook($stale)->assertStatus(400);
    $broken = ['payload' => '{not-json', 'signature' => '', 'event_id' => 'evt_bad'];
    $brokenTime = (string) Carbon::now()->timestamp;
    $broken['signature'] = 't='.$brokenTime.',v1='.hash_hmac('sha256', $brokenTime.'.'.$broken['payload'], (string) config('services.stripe.webhook_secret'));
    postStripeWebhook($broken)->assertStatus(400);
    postStripeWebhook(signedStripeEvent(['object' => 'checkout.session']))->assertStatus(400);
    expect(DB::table('provider_events')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('CheckoutOrdersWebhook treats a missing secret as retryable and rejects a changed replay body', function (): void {
    config(['services.stripe.webhook_secret' => '']);
    postStripeWebhook(signedStripeEvent(['id' => 'evt_test_inbox_missing_secret']))->assertStatus(503);
    config(['services.stripe.webhook_secret' => 'whsec_test_fixture']);
    $first = signedStripeEvent(['id' => 'evt_test_inbox_digest']);
    postStripeWebhook($first)->assertNoContent();
    $changed = signedStripeEvent(['id' => 'evt_test_inbox_digest', 'type' => 'checkout.session.expired']);
    postStripeWebhook($changed)->assertStatus(400);
    expect(DB::table('provider_events')->where('event_id', 'evt_test_inbox_digest')->count())->toBe(1);
});

it('CheckoutOrdersWebhook keeps a persistence failure retryable and does not mark the inbox accepted', function (): void {
    Schema::rename('provider_events', 'provider_events_offline');
    try {
        postStripeWebhook(signedStripeEvent())->assertStatus(503);
        expect(Schema::hasTable('provider_events'))->toBeFalse();
        Queue::assertNothingPushed();
    } finally {
        Schema::rename('provider_events_offline', 'provider_events');
    }
    expect(DB::table('provider_events')->count())->toBe(0);
});

it('CheckoutOrdersWebhook job loads the persisted event and rejects an oversized payload', function (): void {
    $signed = signedStripeEvent(['id' => 'evt_test_job']);
    $id = app(AcceptProviderEvent::class)->accept($signed['payload'], $signed['signature'], null);
    (new ProcessPaymentEvent($id))->handle();
    expect(DB::table('provider_events')->where('id', $id)->value('processing_state'))->toBe('processed');
    expect(fn () => (new ProcessPaymentEvent((string) Str::uuid()))->handle())->toThrow(RuntimeException::class);
    postStripeWebhook(signedStripeEvent([
        'id' => 'evt_test_oversize',
        'data' => ['object' => ['pad' => str_repeat('x', 262144)]],
    ]))->assertStatus(400);
});

it('CheckoutOrdersWebhook HTTP keeps privileged staff behind two-factor and leaves the storefront closed', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/products')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/products', ['product' => ['title' => 'Denied']])->assertForbidden();
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->postJson('/store-api/v1/checkout-sessions/'.(string) Str::uuid().'/payment')
        ->assertStatus(409);
    $this->get('/')->assertOk();
    expect(class_exists(StripeController::class))->toBeTrue();
});
