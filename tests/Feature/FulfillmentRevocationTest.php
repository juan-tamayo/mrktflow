<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\AcceptQuote;
use App\Domain\CheckoutOrders\Actions\CancelOrder;
use App\Domain\CheckoutOrders\Actions\CreateCheckoutIntent;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\ReconcileExternalAdjustment;
use App\Domain\CheckoutOrders\Actions\RequestRefund;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Data\DisputeObservation;
use App\Domain\CheckoutOrders\Data\ExternalRefundObservation;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Fulfillment\Actions\AuthorizeDownload;
use App\Domain\Fulfillment\Actions\IssueEntitlements;
use App\Domain\Fulfillment\Actions\RevokeEntitlements;
use App\Domain\Fulfillment\Data\DownloadPrincipal;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Platform\Actions\ConsumeEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-16T20:00:00Z')->utc());
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

/**
 * Two distinct digital products with ready versions, for line-precision
 * revocation checks.
 *
 * @return array{owner: mixed, alpha: string, beta: string}
 */
function revTwoDigitalCatalog(): array
{
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['amount' => 300])]])],
    ]);
    pricingSavedTaxRules($owner);
    $image = catalogReadyImage($owner->id);
    $variants = [];
    foreach ([['alpha', 'Alpha Guide', 'alpha-guide', 'ALPHA-001', 500], ['beta', 'Beta Guide', 'beta-guide', 'BETA-001', 700]] as [$key, $title, $slug, $sku, $price]) {
        $product = app(SaveProduct::class)->save($owner, catalogDigitalProductInput($image, [
            'status' => 'published', 'title' => $title, 'slug' => $slug, 'sku' => $sku, 'price_amount' => $price,
        ]));
        $variant = (string) DB::table('product_variants')->where('product_id', $product['id'])->value('id');
        $ready = app(VersionDigitalAsset::class)->version($owner, [
            'product_id' => $product['id'],
            'label' => $title,
            'file' => catalogPrivateTextFile(),
        ]);
        app(VersionDigitalAsset::class)->attach($owner, [
            'product_id' => $product['id'],
            'variant_id' => $variant,
            'version_ids' => [$ready['id']],
        ]);
        $variants[$key] = $variant;
    }

    return ['owner' => $owner, 'alpha' => $variants['alpha'], 'beta' => $variants['beta']];
}

function revLineId(string $orderId, string $variantId): string
{
    return (string) DB::table('order_lines')->where('order_id', $orderId)->where('variant_id', $variantId)->value('id');
}

function revEntitlementStatus(string $orderId, ?string $lineId = null): ?string
{
    $query = DB::table('entitlements')->where('order_id', $orderId);
    if ($lineId !== null) {
        $query->where('order_line_id', $lineId);
    }

    $status = $query->value('status');

    return is_string($status) ? $status : null;
}

function revTimelineCount(string $orderId, string $type): int
{
    return DB::table('order_events')->where('order_id', $orderId)->where('type', $type)->count();
}

function revStreamStatus(AuthorizeDownload $downloads, DownloadPrincipal $principal, string $transferId): int
{
    try {
        $downloads->beginStream($principal, $transferId);

        return 200;
    } catch (HttpException $exception) {
        return $exception->getStatusCode();
    }
}

it('FulfillmentRevocation revokes an issued entitlement on a full staff refund of its line and kills its grants and transfers', function (): void {
    $catalog = entitlementCatalog();
    $buyer = dlCustomer('rev-full@example.test');
    $paid = dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::customer($buyer->id), $buyer->email, 'cs_rev_full', 'pi_rev_full');
    $orderId = $paid['order_id'];
    $lineId = revLineId($orderId, $catalog['guide']);
    $entitlementId = dgEntitlementId($orderId);
    expect(revEntitlementStatus($orderId))->toBe('active');

    $principal = DownloadPrincipal::account($buyer, 'session-rev');
    $downloads = app(AuthorizeDownload::class);
    // One grant left unconsumed, one started transfer that streams fine.
    $pending = $downloads->issue($principal, $entitlementId);
    $started = $downloads->issue($principal, $entitlementId);
    $session = $downloads->start($principal, $started['grant_id'], $started['token']);
    expect(revStreamStatus($downloads, $principal, $session['transfer_id']))->toBe(200);

    entitlementFakeRefundSucceeded();
    $refund = app(RequestRefund::class)->request($catalog['owner'], $orderId, [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Customer changed their mind.',
    ], (string) Str::uuid());
    expect($refund['status'])->toBe('succeeded');

    // The line's entitlement is revoked, its unconsumed grant is revoked
    // and its live transfer can no longer open a stream.
    expect(revEntitlementStatus($orderId))->toBe('revoked');
    $grant = DB::table('download_grants')->where('id', $pending['grant_id'])->first();
    expect($grant->revoked_at)->not->toBeNull()
        ->and($grant->token_digest)->toBeNull();
    expect(DB::table('download_transfers')->where('id', $session['transfer_id'])->value('status'))->toBe('revoked')
        ->and(revStreamStatus($downloads, $principal, $session['transfer_id']))->toBe(403);
    expect(revTimelineCount($orderId, 'entitlements_revoked'))->toBe(1);

    // The revoked grant cannot be started after the fact.
    try {
        $downloads->start($principal, $pending['grant_id'], $pending['token']);
        $startCode = 200;
    } catch (HttpException $exception) {
        $startCode = $exception->getStatusCode();
    }
    expect($startCode)->toBe(404)
        ->and((int) DB::table('entitlements')->where('id', $entitlementId)->value('downloads_used'))->toBe(1);

    // A stale paid-event replay can never resurrect the revoked row, and
    // re-running the sync is a silent no-op.
    $again = app(IssueEntitlements::class)->issueForOrder($orderId, $paid['event_id'] ?? null);
    expect($again[0]['status'])->toBe('revoked')->and($again[0]['created'])->toBeFalse();
    app(RevokeEntitlements::class)->syncForOrder($orderId);
    expect(revEntitlementStatus($orderId))->toBe('revoked')
        ->and(revTimelineCount($orderId, 'entitlements_revoked'))->toBe(1);
});

it('FulfillmentRevocation revokes only the fully refunded digital line on a two-line order', function (): void {
    $catalog = revTwoDigitalCatalog();
    $paid = entitlementPaidOrder([
        ['variant' => $catalog['alpha'], 'quantity' => 1],
        ['variant' => $catalog['beta'], 'quantity' => 1],
    ], 'guest-rev-lines', 'cs_rev_lines', 'pi_rev_lines');
    $orderId = $paid['order_id'];
    $alphaLine = $paid['line_ids'][$catalog['alpha']];
    $betaLine = $paid['line_ids'][$catalog['beta']];
    entitlementConsume($paid);
    expect(revEntitlementStatus($orderId, $alphaLine))->toBe('active')
        ->and(revEntitlementStatus($orderId, $betaLine))->toBe('active');

    entitlementFakeRefundSucceeded();
    $refund = app(RequestRefund::class)->request($catalog['owner'], $orderId, [
        'lines' => [['id' => $alphaLine, 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Only the alpha guide goes back.',
    ], (string) Str::uuid());
    expect($refund['status'])->toBe('succeeded');
    expect(revEntitlementStatus($orderId, $alphaLine))->toBe('revoked')
        ->and(revEntitlementStatus($orderId, $betaLine))->toBe('active')
        ->and(revTimelineCount($orderId, 'entitlements_revoked'))->toBe(1);
});

it('FulfillmentRevocation retains digital access after a physical-line refund and a goodwill refund', function (): void {
    $catalog = entitlementCatalog();
    $paid = entitlementPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 1],
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], 'guest-rev-retain', 'cs_rev_retain', 'pi_rev_retain');
    $orderId = $paid['order_id'];
    entitlementConsume($paid);
    expect(revEntitlementStatus($orderId))->toBe('active');

    entitlementFakeRefundSucceeded();
    $physical = app(RequestRefund::class)->request($catalog['owner'], $orderId, [
        'lines' => [['id' => $paid['line_ids'][$catalog['lamp']], 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Lamp arrived damaged.',
    ], (string) Str::uuid());
    expect($physical['status'])->toBe('succeeded');
    expect(revEntitlementStatus($orderId))->toBe('active')
        ->and(revTimelineCount($orderId, 'entitlements_revoked'))->toBe(0);

    $goodwill = app(RequestRefund::class)->request($catalog['owner'], $orderId, [
        'lines' => [],
        'amount' => 100,
        'shipping_amount' => 0,
        'reason' => 'Goodwill adjustment.',
    ], (string) Str::uuid());
    expect($goodwill['status'])->toBe('succeeded')->and($goodwill['kind'])->toBe('goodwill');
    expect(revEntitlementStatus($orderId))->toBe('active')
        ->and(revTimelineCount($orderId, 'entitlements_revoked'))->toBe(0);
});

it('FulfillmentRevocation provider refunds revoke only when they take the whole capture', function (): void {
    $catalog = entitlementCatalog();
    $partial = entitlementPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], 'guest-rev-prov-part', 'cs_rev_pp', 'pi_rev_pp');
    entitlementConsume($partial);
    $reconcile = app(ReconcileExternalAdjustment::class);
    $partialObservation = new ExternalRefundObservation(
        're_rev_partial', $partial['capture_id'], intdiv($partial['amount'], 2), 'USD', 'succeeded', null, null,
    );
    $reconcile->reconcileRefund($partialObservation, (string) Str::uuid());
    expect(DB::table('orders')->where('id', $partial['order_id'])->value('financial_status'))->toBe('partially_refunded')
        ->and(revEntitlementStatus($partial['order_id']))->toBe('active')
        ->and(revTimelineCount($partial['order_id'], 'entitlements_revoked'))->toBe(0);

    $full = entitlementPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], 'guest-rev-prov-full', 'cs_rev_pf', 'pi_rev_pf');
    entitlementConsume($full);
    $fullObservation = new ExternalRefundObservation(
        're_rev_full', $full['capture_id'], $full['amount'], 'USD', 'succeeded', null, null,
    );
    $eventId = (string) Str::uuid();
    $reconcile->reconcileRefund($fullObservation, $eventId);
    expect(DB::table('orders')->where('id', $full['order_id'])->value('financial_status'))->toBe('refunded')
        ->and(revEntitlementStatus($full['order_id']))->toBe('revoked')
        ->and(revTimelineCount($full['order_id'], 'entitlements_revoked'))->toBe(1);

    // Redelivering the same provider fact changes nothing twice.
    $reconcile->reconcileRefund($fullObservation, $eventId);
    expect(DB::table('refunds')->where('order_id', $full['order_id'])->count())->toBe(1)
        ->and(revTimelineCount($full['order_id'], 'entitlements_revoked'))->toBe(1);
});

it('FulfillmentRevocation dispute open suspends issued access and won restores it under current state', function (): void {
    $catalog = entitlementCatalog();
    $buyer = dlCustomer('rev-dispute@example.test');
    $paid = dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::customer($buyer->id), $buyer->email, 'cs_rev_dis', 'pi_rev_dis');
    $orderId = $paid['order_id'];
    $entitlementId = dgEntitlementId($orderId);
    $principal = DownloadPrincipal::account($buyer, 'session-dispute');
    $downloads = app(AuthorizeDownload::class);
    $grant = $downloads->issue($principal, $entitlementId);
    $session = $downloads->start($principal, $grant['grant_id'], $grant['token']);
    expect(revStreamStatus($downloads, $principal, $session['transfer_id']))->toBe(200);

    $reconcile = app(ReconcileExternalAdjustment::class);
    $reconcile->reconcileDispute(new DisputeObservation(
        'dp_rev_1', $paid['capture_id'], $paid['amount'], 'USD', 'opened', 'fraudulent',
    ), (string) Str::uuid());
    // Suspension freezes access without destroying the transfer session.
    expect(revEntitlementStatus($orderId))->toBe('suspended')
        ->and(DB::table('download_transfers')->where('id', $session['transfer_id'])->value('status'))->toBe('active')
        ->and(revStreamStatus($downloads, $principal, $session['transfer_id']))->toBe(403)
        ->and(revTimelineCount($orderId, 'entitlements_suspended'))->toBe(1);
    try {
        $downloads->issue($principal, $entitlementId);
        $issueCode = 200;
    } catch (HttpException $exception) {
        $issueCode = $exception->getStatusCode();
    }
    expect($issueCode)->toBe(403);

    $reconcile->reconcileDispute(new DisputeObservation(
        'dp_rev_1', $paid['capture_id'], $paid['amount'], 'USD', 'won', 'fraudulent',
    ), (string) Str::uuid());
    expect(revEntitlementStatus($orderId))->toBe('active')
        ->and(revStreamStatus($downloads, $principal, $session['transfer_id']))->toBe(200)
        ->and(revTimelineCount($orderId, 'entitlements_restored'))->toBe(1);

    // Replaying the terminal dispute fact restores nothing twice.
    $reconcile->reconcileDispute(new DisputeObservation(
        'dp_rev_1', $paid['capture_id'], $paid['amount'], 'USD', 'won', 'fraudulent',
    ), (string) Str::uuid());
    expect(revTimelineCount($orderId, 'entitlements_restored'))->toBe(1)
        ->and(revEntitlementStatus($orderId))->toBe('active');
});

it('FulfillmentRevocation dispute lost revokes issued access, whether opened first or observed closed', function (): void {
    $catalog = entitlementCatalog();
    $buyer = dlCustomer('rev-lost@example.test');
    $paid = dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::customer($buyer->id), $buyer->email, 'cs_rev_lost', 'pi_rev_lost');
    $orderId = $paid['order_id'];
    $entitlementId = dgEntitlementId($orderId);
    $principal = DownloadPrincipal::account($buyer, 'session-lost');
    $downloads = app(AuthorizeDownload::class);
    $grant = $downloads->issue($principal, $entitlementId);
    $session = $downloads->start($principal, $grant['grant_id'], $grant['token']);

    $reconcile = app(ReconcileExternalAdjustment::class);
    $reconcile->reconcileDispute(new DisputeObservation(
        'dp_rev_lost', $paid['capture_id'], $paid['amount'], 'USD', 'opened', 'fraudulent',
    ), (string) Str::uuid());
    expect(revEntitlementStatus($orderId))->toBe('suspended');
    $reconcile->reconcileDispute(new DisputeObservation(
        'dp_rev_lost', $paid['capture_id'], $paid['amount'], 'USD', 'lost', 'fraudulent',
    ), (string) Str::uuid());
    expect(revEntitlementStatus($orderId))->toBe('revoked')
        ->and(DB::table('download_transfers')->where('id', $session['transfer_id'])->value('status'))->toBe('revoked')
        ->and(revStreamStatus($downloads, $principal, $session['transfer_id']))->toBe(403)
        ->and(revTimelineCount($orderId, 'entitlements_revoked'))->toBe(1);

    // A dispute first observed already closed as lost revokes directly.
    $second = entitlementPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], 'guest-rev-lost2', 'cs_rev_lost2', 'pi_rev_lost2');
    entitlementConsume($second);
    expect(revEntitlementStatus($second['order_id']))->toBe('active');
    $reconcile->reconcileDispute(new DisputeObservation(
        'dp_rev_lost2', $second['capture_id'], $second['amount'], 'USD', 'lost', 'fraudulent',
    ), (string) Str::uuid());
    expect(revEntitlementStatus($second['order_id']))->toBe('revoked');
});

it('FulfillmentRevocation a dispute won after a full refund during the dispute restores nothing', function (): void {
    $catalog = entitlementCatalog();
    $paid = entitlementPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], 'guest-rev-wonlate', 'cs_rev_wl', 'pi_rev_wl');
    $orderId = $paid['order_id'];
    entitlementConsume($paid);
    $reconcile = app(ReconcileExternalAdjustment::class);
    $reconcile->reconcileDispute(new DisputeObservation(
        'dp_rev_wl', $paid['capture_id'], $paid['amount'], 'USD', 'opened', 'fraudulent',
    ), (string) Str::uuid());
    expect(revEntitlementStatus($orderId))->toBe('suspended');

    $reconcile->reconcileRefund(new ExternalRefundObservation(
        're_rev_wl', $paid['capture_id'], $paid['amount'], 'USD', 'succeeded', null, null,
    ), (string) Str::uuid());
    expect(DB::table('orders')->where('id', $orderId)->value('financial_status'))->toBe('refunded')
        ->and(revEntitlementStatus($orderId))->toBe('revoked');

    $reconcile->reconcileDispute(new DisputeObservation(
        'dp_rev_wl', $paid['capture_id'], $paid['amount'], 'USD', 'won', 'fraudulent',
    ), (string) Str::uuid());
    expect(revEntitlementStatus($orderId))->toBe('revoked')
        ->and(revTimelineCount($orderId, 'entitlements_restored'))->toBe(0);
});

it('FulfillmentRevocation rejects a transfer start when revocation lands between grant issue and start', function (): void {
    $catalog = entitlementCatalog();
    $buyer = dlCustomer('rev-between@example.test');
    $paid = dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::customer($buyer->id), $buyer->email, 'cs_rev_between', 'pi_rev_between');
    $orderId = $paid['order_id'];
    $lineId = revLineId($orderId, $catalog['guide']);
    $entitlementId = dgEntitlementId($orderId);

    $this->actingAs($buyer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/downloads')->assertOk()
        ->assertSee('/account/downloads/'.$entitlementId.'/grants', false);
    $this->post('/account/downloads/'.$entitlementId.'/grants')->assertRedirect();
    $grantId = (string) DB::table('download_grants')->where('entitlement_id', $entitlementId)->value('id');
    $grantUrl = '/account/download-grants/'.$grantId;
    $status = $this->get($grantUrl);
    $status->assertOk();
    preg_match('/name="token" value="([a-f0-9]{64})"/', (string) $status->getContent(), $match);
    expect($match[1] ?? null)->not->toBeNull();

    entitlementFakeRefundSucceeded();
    $refund = app(RequestRefund::class)->request($catalog['owner'], $orderId, [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Refunded before the download ever started.',
    ], (string) Str::uuid());
    expect($refund['status'])->toBe('succeeded')
        ->and(revEntitlementStatus($orderId))->toBe('revoked');

    $this->post($grantUrl.'/start', ['token' => $match[1]])->assertNotFound();
    expect((int) DB::table('entitlements')->where('id', $entitlementId)->value('downloads_used'))->toBe(0)
        ->and(DB::table('download_transfers')->where('entitlement_id', $entitlementId)->count())->toBe(0)
        ->and(DB::table('download_grants')->where('id', $grantId)->value('revoked_at'))->not->toBeNull();
    $this->get('/account/downloads')->assertOk()->assertDontSee('Studio Lighting Guide');
});

it('FulfillmentRevocation cancelling a free digital order revokes its entitlement', function (): void {
    $catalog = entitlementCatalog();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'rev-cancel@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true, 'session_version' => 1, 'email_verified_at' => now(),
    ])->save();
    $owner = CartOwner::customer($customer->id);
    $cart = app(UpdateCart::class)->replaceLine($owner, [
        'variant_id' => $catalog['guide'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $quote = app(CreateQuote::class)->create($owner, checkoutQuoteInput($cart['revision'], [
        'email' => $customer->email,
        'coupon' => 'FREE100',
        'shipping' => null,
    ]));
    app(AcceptQuote::class)->accept($owner, $quote['id'], $quote['fingerprint'], true);
    $intent = app(CreateCheckoutIntent::class)->create($owner, $quote['id'], (string) Str::uuid());
    $paidEvent = DB::table('outbox_events')->where('event_type', 'checkout.order.paid')
        ->where('intent_key', 'order:paid:'.$intent['order_id'])->first();
    expect($paidEvent)->not->toBeNull();
    app(ConsumeEvent::class)->consume((string) $paidEvent->id, 'fulfillment.entitlements');
    expect(revEntitlementStatus($intent['order_id']))->toBe('active');

    $view = app(CancelOrder::class)->cancel($catalog['owner'], $intent['order_id'], [
        'reason' => 'Customer asked to cancel.',
    ], (string) Str::uuid());
    expect($view['status'])->toBe('cancelled')
        ->and(revEntitlementStatus($intent['order_id']))->toBe('revoked')
        ->and(revTimelineCount($intent['order_id'], 'entitlements_revoked'))->toBe(1);
});
