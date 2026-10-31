<?php

declare(strict_types=1);

use App\Domain\CheckoutOrders\Actions\ReconcileExternalAdjustment;
use App\Domain\CheckoutOrders\Actions\RequestRefund;
use App\Domain\CheckoutOrders\Data\DisputeObservation;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Fulfillment\Actions\ClaimGuestOrders;
use App\Domain\Fulfillment\Actions\ReadDownloads;
use App\Domain\Fulfillment\Actions\VerifyGuestOrder;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Platform\Actions\ConsumeEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-16T18:00:00Z')->utc());
    config([
        'app.url' => 'http://127.0.0.1:8000',
        'services.stripe.secret' => 'sk_test_fixture',
        'services.stripe.account' => 'acct_fixture',
        'services.stripe.live' => false,
        'services.stripe.webhook_secret' => 'whsec_test_fixture',
    ]);
});
afterEach(function (): void {
    Carbon::setTestNow();
});

function dlCustomer(string $email, bool $verified = true): CustomerAccount
{
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => $email,
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true, 'session_version' => 1,
        'email_verified_at' => $verified ? now() : null,
    ])->save();

    return $customer;
}

/**
 * @param  list<array{variant: string, quantity: int}>  $lines
 * @return array<string, mixed>
 */
function dlPaidOrder(array $lines, CartOwner $owner, string $email, string $sessionId, string $captureId): array
{
    $paid = trackingPaidOrder($lines, $owner, $email, $sessionId, $captureId);
    $paidEvent = DB::table('outbox_events')->where('event_type', 'checkout.order.paid')
        ->where('intent_key', 'order:paid:'.$paid['order_id'])->first();
    expect($paidEvent)->not->toBeNull();
    app(ConsumeEvent::class)->consume((string) $paidEvent->id, 'fulfillment.entitlements');

    return $paid + ['capture_id' => $captureId, 'amount' => (int) $paid['grand_total']['amount']];
}

/**
 * @return array<string, mixed>
 */
function dlGuestDigitalOrder(array $catalog, string $guestToken, string $sessionId, string $captureId): array
{
    return dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::guest($guestToken), 'guest@example.test', $sessionId, $captureId);
}

it('FulfillmentDownloadIdentity issues a digest-only expiring token and queued mail intent only for the matching guest email', function (): void {
    $catalog = entitlementCatalog();
    $paid = dlGuestDigitalOrder($catalog, 'guest-dl-issue', 'cs_dl_issue', 'pi_dl_issue');
    $orderId = $paid['order_id'];
    expect(DB::table('entitlements')->where('order_id', $orderId)->value('status'))->toBe('active');

    $issued = app(VerifyGuestOrder::class)->requestAccess($orderId, 'Guest@Example.Test');
    expect($issued)->not->toBeNull()
        ->and($issued['token'])->toMatch('/^[a-f0-9]{64}$/D');
    $row = DB::table('guest_order_verifications')->where('order_id', $orderId)->first();
    expect($row)->not->toBeNull()
        ->and($row->token_digest)->toBe(hash('sha256', $issued['token']))
        ->and($row->token_digest)->not->toBe($issued['token'])
        ->and($row->consumed_at)->toBeNull()
        ->and(Carbon::parse($row->expires_at)->equalTo(Carbon::parse('2026-10-17T18:00:00Z')))->toBeTrue();
    $intent = DB::table('download_access_intents')->where('order_id', $orderId)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->template)->toBe('download-access')
        ->and($intent->kind)->toBe('request')
        ->and($intent->status)->toBe('queued')
        ->and($intent->recipient)->toBe('guest@example.test')
        ->and($intent->intent_key)->toBe('download-access:'.$row->id);
    expect(DB::table('order_events')->where('order_id', $orderId)->where('type', 'download_access_requested')->count())->toBe(1);
    Mail::assertNothingOutgoing();

    $order = DB::table('orders')->where('id', $orderId)->first();
    $html = view('mail.download-access', [
        'order' => ['number' => (string) $order->number],
        'url' => 'http://127.0.0.1:8000/orders/download-access#'.$issued['token'],
        'token' => $issued['token'],
        'expiry' => '24 hours',
    ])->render();
    expect($html)->toContain((string) $order->number)
        ->toContain('/orders/download-access#'.$issued['token'])
        ->toContain($issued['token'])
        ->toContain('24 hours')
        ->not->toContain('media')
        ->not->toContain('storage');

    // Wrong email, unknown order and account-owned orders issue nothing.
    expect(app(VerifyGuestOrder::class)->requestAccess($orderId, 'other@example.test'))->toBeNull()
        ->and(app(VerifyGuestOrder::class)->requestAccess((string) Str::uuid(), 'guest@example.test'))->toBeNull();
    $customer = dlCustomer('owned-dl@example.test');
    $owned = dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::customer($customer->id), $customer->email, 'cs_dl_owned', 'pi_dl_owned');
    expect(app(VerifyGuestOrder::class)->requestAccess($owned['order_id'], $customer->email))->toBeNull();
    expect(DB::table('guest_order_verifications')->count())->toBe(1)
        ->and(DB::table('download_access_intents')->count())->toBe(1);

    // Reissue revokes the previous token; only the latest can be consumed.
    $reissued = app(VerifyGuestOrder::class)->requestAccess($orderId, 'guest@example.test');
    expect($reissued)->not->toBeNull()->and($reissued['token'])->not->toBe($issued['token']);
    expect(DB::table('guest_order_verifications')->where('order_id', $orderId)->whereNotNull('revoked_at')->count())->toBe(1)
        ->and(DB::table('download_access_intents')->where('order_id', $orderId)->count())->toBe(2);
    $this->post('/orders/download-access/confirm', ['challenge_token' => $issued['token']])->assertNotFound();
    $this->get('/orders/'.$orderId.'/downloads')->assertNotFound();
});

it('FulfillmentDownloadIdentity scanner GET never consumes or authenticates and only the confirmation POST grants scoped access', function (): void {
    $catalog = entitlementCatalog();
    $paid = dlGuestDigitalOrder($catalog, 'guest-dl-scan', 'cs_dl_scan', 'pi_dl_scan');
    $orderId = $paid['order_id'];
    $other = dlGuestDigitalOrder($catalog, 'guest-dl-scan2', 'cs_dl_scan2', 'pi_dl_scan2');
    $issued = app(VerifyGuestOrder::class)->requestAccess($orderId, 'guest@example.test');

    // Scanner / prefetch traffic: landing page, direct downloads URL and a
    // token carried in a query string must all be inert.
    $this->get('/orders/download-access')->assertOk()->assertSee('Confirm download access');
    $this->get('/orders/'.$orderId.'/downloads')->assertNotFound();
    $this->get('/orders/'.$orderId.'/downloads?token='.$issued['token'])->assertNotFound();
    $this->get('/orders/download-access#'.$issued['token'])->assertOk();
    $row = DB::table('guest_order_verifications')->where('order_id', $orderId)->first();
    expect($row->consumed_at)->toBeNull()->and($row->token_digest)->toBe(hash('sha256', $issued['token']));

    $sessionBefore = session()->getId();
    $this->post('/orders/download-access/confirm', ['challenge_token' => $issued['token']])
        ->assertRedirect('/orders/'.$orderId.'/downloads');
    expect(session()->getId())->not->toBe($sessionBefore);
    $row = DB::table('guest_order_verifications')->where('order_id', $orderId)->first();
    expect($row->consumed_at)->not->toBeNull()->and($row->token_digest)->toBeNull();

    $this->get('/orders/'.$orderId.'/downloads')->assertOk()
        ->assertSee('Lighting guide')
        ->assertSee('Downloads');
    // The grant is scoped: a different guest order stays invisible.
    $this->get('/orders/'.$other['order_id'].'/downloads')->assertNotFound();

    // Replay of the consumed token fails and changes nothing.
    $this->post('/orders/download-access/confirm', ['challenge_token' => $issued['token']])->assertNotFound();
    expect(DB::table('guest_order_verifications')->where('order_id', $orderId)->whereNotNull('consumed_at')->count())->toBe(1);
    $this->get('/orders/'.$orderId.'/downloads')->assertOk();
});

it('FulfillmentDownloadIdentity expired guest token fails and grants nothing', function (): void {
    $catalog = entitlementCatalog();
    $paid = dlGuestDigitalOrder($catalog, 'guest-dl-expired', 'cs_dl_exp', 'pi_dl_exp');
    $orderId = $paid['order_id'];
    $issued = app(VerifyGuestOrder::class)->requestAccess($orderId, 'guest@example.test');

    Carbon::setTestNow(Carbon::parse('2026-10-17T18:00:01Z')->utc());
    $this->post('/orders/download-access/confirm', ['challenge_token' => $issued['token']])->assertNotFound();
    $this->get('/orders/'.$orderId.'/downloads')->assertNotFound();
    expect(DB::table('guest_order_verifications')->where('order_id', $orderId)->value('consumed_at'))->toBeNull();
});

it('FulfillmentDownloadIdentity account area shows only the signed-in customers own active files', function (): void {
    $catalog = entitlementCatalog();
    $owner = dlCustomer('owner-dl@example.test');
    $stranger = dlCustomer('stranger-dl@example.test');
    $paid = dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
        ['variant' => $catalog['lamp'], 'quantity' => 1],
    ], CartOwner::customer($owner->id), $owner->email, 'cs_dl_acct', 'pi_dl_acct');
    expect(DB::table('entitlements')->where('order_id', $paid['order_id'])->value('status'))->toBe('active');

    $this->get('/account/downloads')->assertRedirect('/account/login');
    $this->actingAs($owner, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/downloads')->assertOk()
        ->assertSee('Lighting guide')
        ->assertSee('Downloads');
    $area = app(ReadDownloads::class)->forCustomer($owner);
    expect($area['orders'])->toHaveCount(1)
        ->and($area['orders'][0]['downloads'])->toHaveCount(1)
        ->and($area['orders'][0]['downloads'][0]['label'])->toBe('Lighting guide')
        ->and($area['orders'][0]['downloads'][0]['version'])->toBe(1);

    $this->actingAs($stranger, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/downloads')->assertOk()->assertDontSee('Lighting guide');
    expect(app(ReadDownloads::class)->forCustomer($stranger)['orders'])->toBe([]);
});

it('FulfillmentDownloadIdentity suspended and revoked entitlements show no downloadable files', function (): void {
    $catalog = entitlementCatalog();
    $disputed = entitlementPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], 'guest-dl-dispute', 'cs_dl_dispute', 'pi_dl_dispute');
    app(ReconcileExternalAdjustment::class)->reconcileDispute(new DisputeObservation(
        'dp_dl_1', $disputed['capture_id'], $disputed['amount'], 'USD', 'opened', 'fraudulent',
    ), (string) Str::uuid());
    app(ConsumeEvent::class)->consume($disputed['event_id'], 'fulfillment.entitlements');
    expect(DB::table('entitlements')->where('order_id', $disputed['order_id'])->value('status'))->toBe('suspended');
    $issued = app(VerifyGuestOrder::class)->requestAccess($disputed['order_id'], 'guest@example.test');
    $this->post('/orders/download-access/confirm', ['challenge_token' => $issued['token']])
        ->assertRedirect('/orders/'.$disputed['order_id'].'/downloads');
    $this->get('/orders/'.$disputed['order_id'].'/downloads')->assertOk()->assertDontSee('Lighting guide');
    expect(app(ReadDownloads::class)->forOrder($disputed['order_id'])['downloads'])->toBe([]);

    $refunded = entitlementPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], 'guest-dl-refund', 'cs_dl_refund', 'pi_dl_refund');
    entitlementFakeRefundSucceeded();
    $lineId = $refunded['line_ids'][$catalog['guide']];
    $refund = app(RequestRefund::class)->request($catalog['owner'], $refunded['order_id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Customer changed their mind.',
    ], (string) Str::uuid());
    expect($refund['status'])->toBe('succeeded');
    app(ConsumeEvent::class)->consume($refunded['event_id'], 'fulfillment.entitlements');
    expect(DB::table('entitlements')->where('order_id', $refunded['order_id'])->value('status'))->toBe('revoked');
    $issued = app(VerifyGuestOrder::class)->requestAccess($refunded['order_id'], 'guest@example.test');
    $this->post('/orders/download-access/confirm', ['challenge_token' => $issued['token']])
        ->assertRedirect('/orders/'.$refunded['order_id'].'/downloads');
    $this->get('/orders/'.$refunded['order_id'].'/downloads')->assertOk()->assertDontSee('Lighting guide');
});

it('FulfillmentDownloadIdentity verified account claims eligible guest orders and unverified or wrong-email accounts claim nothing', function (): void {
    $catalog = entitlementCatalog();
    $paid = dlGuestDigitalOrder($catalog, 'guest-dl-claim', 'cs_dl_claim', 'pi_dl_claim');
    $orderId = $paid['order_id'];
    $token = app(VerifyGuestOrder::class)->requestAccess($orderId, 'guest@example.test');

    // One account, registered with the guest order's email but not yet
    // verified: the classic account-link attack, and it claims nothing.
    $heir = dlCustomer('guest@example.test', verified: false);
    $this->actingAs($heir, 'customer')->withSession(['customer_session_version' => 1]);
    $this->post('/account/downloads/claim')->assertForbidden();
    expect(DB::table('orders')->where('id', $orderId)->value('customer_id'))->toBeNull()
        ->and(DB::table('orders')->where('id', $orderId)->value('guest_token_hash'))->not->toBeNull();
    expect(fn () => app(ClaimGuestOrders::class)->claim($heir))->toThrow(AuthorizationException::class);

    $wrong = dlCustomer('someone-else@example.test');
    $this->actingAs($wrong, 'customer')->withSession(['customer_session_version' => 1]);
    $this->post('/account/downloads/claim')->assertRedirect('/account/downloads');
    expect(DB::table('orders')->where('id', $orderId)->value('customer_id'))->toBeNull();

    // Verifying that same account's email is what unlocks the claim.
    $heir->forceFill(['email_verified_at' => now()])->save();
    $this->actingAs($heir, 'customer')->withSession(['customer_session_version' => 1]);
    $this->post('/account/downloads/claim')->assertRedirect('/account/downloads');
    $order = DB::table('orders')->where('id', $orderId)->first();
    expect($order->customer_id)->toBe($heir->id)
        ->and($order->guest_token_hash)->toBeNull()
        ->and(DB::table('entitlements')->where('order_id', $orderId)->value('customer_id'))->toBe($heir->id);
    $this->get('/account/downloads')->assertOk()->assertSee('Lighting guide');
    $this->get('/account/orders/'.$orderId)->assertOk();

    // Replay claims nothing twice, and the pre-claim guest token is dead
    // because the order is no longer a guest order.
    expect(app(ClaimGuestOrders::class)->claim($heir))->toBe(0);
    $this->post('/orders/download-access/confirm', ['challenge_token' => $token['token']])->assertNotFound();
    $this->get('/orders/'.$orderId.'/downloads')->assertNotFound();
});

it('FulfillmentDownloadIdentity a guest token confirmed inside another customers session never links the order to that account', function (): void {
    $catalog = entitlementCatalog();
    $paid = dlGuestDigitalOrder($catalog, 'guest-dl-link', 'cs_dl_link', 'pi_dl_link');
    $orderId = $paid['order_id'];
    $issued = app(VerifyGuestOrder::class)->requestAccess($orderId, 'guest@example.test');

    $bystander = dlCustomer('bystander-dl@example.test');
    $this->actingAs($bystander, 'customer')->withSession(['customer_session_version' => 1]);
    $this->post('/orders/download-access/confirm', ['challenge_token' => $issued['token']])
        ->assertRedirect('/orders/'.$orderId.'/downloads');
    // Possession of the emailed token grants only the scoped session view.
    $this->get('/orders/'.$orderId.'/downloads')->assertOk()->assertSee('Lighting guide');
    // It never becomes account property: no claim, no account-area entry.
    expect(DB::table('orders')->where('id', $orderId)->value('customer_id'))->toBeNull();
    $this->get('/account/downloads')->assertOk()->assertDontSee('Lighting guide');
    expect(app(ReadDownloads::class)->forCustomer($bystander)['orders'])->toBe([]);
    expect(app(VerifyGuestOrder::class)->requestAccess($orderId, 'bystander-dl@example.test'))->toBeNull();
});
