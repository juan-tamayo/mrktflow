<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\ReadCustomerOrders;
use App\Domain\CheckoutOrders\Actions\RequestRefund;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Fulfillment\Actions\CreateShipment;
use App\Domain\Identity\Models\CustomerAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

/**
 * A mixed catalog: one tracked physical product and one digital product
 * whose file contents are caller-chosen, so streamed bytes can be asserted
 * exactly.
 *
 * @return array{owner: mixed, lamp: string, guide: string, version_id: string, contents: string}
 */
function mxCatalog(string $contents): array
{
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['amount' => 300])]])],
    ]);
    pricingSavedTaxRules($owner);
    $image = catalogReadyImage($owner->id);
    $lamp = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published', 'price_amount' => 2500,
    ]));
    $lampVariant = (string) DB::table('product_variants')->where('product_id', $lamp['id'])->value('id');
    app(AdjustStock::class)->adjust($owner, [
        'variant_id' => $lampVariant,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 10,
        'low_stock_threshold' => 1,
    ]);
    $guide = app(SaveProduct::class)->save($owner, catalogDigitalProductInput($image, [
        'status' => 'published', 'price_amount' => 500,
    ]));
    $guideVariant = (string) DB::table('product_variants')->where('product_id', $guide['id'])->value('id');
    $ready = app(VersionDigitalAsset::class)->version($owner, [
        'product_id' => $guide['id'],
        'label' => 'Trail guide',
        'file' => catalogPrivateTextFile('trail-guide.txt', $contents),
    ]);
    app(VersionDigitalAsset::class)->attach($owner, [
        'product_id' => $guide['id'],
        'variant_id' => $guideVariant,
        'version_ids' => [$ready['id']],
    ]);

    return [
        'owner' => $owner,
        'lamp' => $lampVariant,
        'guide' => $guideVariant,
        'version_id' => $ready['id'],
        'contents' => $contents,
    ];
}

function mxLineId(string $orderId, string $variantId): string
{
    return (string) DB::table('order_lines')->where('order_id', $orderId)->where('variant_id', $variantId)->value('id');
}

function mxEntitlementId(string $orderId): string
{
    return (string) DB::table('entitlements')->where('order_id', $orderId)->value('id');
}

/**
 * @return array<string, mixed>
 */
function mxOrderRow(string $orderId): array
{
    $order = DB::table('orders')->where('id', $orderId)->first();
    expect($order)->not->toBeNull();

    return get_object_vars($order);
}

/**
 * Drives the account download flow for the given entitlement over HTTP:
 * issue a grant from the downloads area, read the start token off the
 * owner-only grant page, start the transfer and stream the file. Returns
 * the streamed bytes.
 */
function mxStreamGuide(CustomerAccount $buyer, string $entitlementId): string
{
    test()->actingAs($buyer, 'customer')->withSession(['customer_session_version' => 1]);
    $issue = test()->post('/account/downloads/'.$entitlementId.'/grants');
    $issue->assertRedirect();
    $grantId = (string) DB::table('download_grants')->where('entitlement_id', $entitlementId)
        ->whereNull('consumed_at')->orderByDesc('created_at')->orderByDesc('id')->value('id');
    expect($grantId)->not->toBe('');
    $grantUrl = '/account/download-grants/'.$grantId;
    $status = test()->get($grantUrl);
    $status->assertOk();
    preg_match('/name="token" value="([a-f0-9]{64})"/', (string) $status->getContent(), $match);
    expect($match[1] ?? null)->not->toBeNull();
    test()->post($grantUrl.'/start', ['token' => $match[1]])->assertRedirect($grantUrl);
    $transferId = (string) DB::table('download_transfers')->where('grant_id', $grantId)->value('id');
    expect($transferId)->not->toBe('');
    $stream = test()->get('/account/download-transfers/'.$transferId);
    $stream->assertOk();

    return $stream->streamedContent();
}

it('FulfillmentMixedOrder one mixed purchase unlocks downloads while shipping stays pending', function (): void {
    $catalog = mxCatalog("Mixed-order trail guide bytes.\n");
    $buyer = dlCustomer('mixed-unlock@example.test');
    $paid = dlPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 2],
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::customer($buyer->id), $buyer->email, 'cs_mx_unlock', 'pi_mx_unlock');
    $orderId = $paid['order_id'];
    $guideLine = mxLineId($orderId, $catalog['guide']);
    $lampLine = mxLineId($orderId, $catalog['lamp']);

    // One payment, two independent fulfillment halves: the digital line is
    // entitled immediately, the physical line has not moved at all.
    $order = mxOrderRow($orderId);
    expect($order['status'])->toBe('confirmed')
        ->and($order['financial_status'])->toBe('paid')
        ->and($order['fulfillment_status'])->toBe('unfulfilled')
        ->and($order['download_status'])->toBe('entitled')
        ->and(DB::table('shipments')->where('order_id', $orderId)->count())->toBe(0);
    $entitlement = DB::table('entitlements')->where('order_id', $orderId)->where('order_line_id', $guideLine)->first();
    expect($entitlement)->not->toBeNull()
        ->and($entitlement->status)->toBe('active')
        ->and($entitlement->digital_asset_version_id)->toBe($catalog['version_id'])
        ->and(DB::table('entitlements')->where('order_id', $orderId)->where('order_line_id', $lampLine)->count())->toBe(0);

    // The customer surface tells both halves truthfully.
    $detail = app(ReadCustomerOrders::class)->detail($buyer, $orderId);
    expect($detail['financial_status'])->toBe('paid')
        ->and($detail['fulfillment_status'])->toBe('unfulfilled')
        ->and($detail['download_status'])->toBe('entitled')
        ->and($detail['shipments'])->toBe([]);

    // The download is not just a status string: the area lists the file
    // and a full grant/start/stream round-trip serves its bytes while the
    // physical half is still pending.
    $this->actingAs($buyer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/downloads')->assertOk()
        ->assertSee('Trail guide')
        ->assertSee('5 of 5 downloads remaining');
    expect(mxStreamGuide($buyer, (string) $entitlement->id))->toBe($catalog['contents']);
    expect(mxOrderRow($orderId)['fulfillment_status'])->toBe('unfulfilled');

    // Shipping part of the physical half afterwards moves only the
    // physical projection; the digital half stays unlocked throughout.
    app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'carrier' => 'UPS',
        'tracking_number' => '1ZMIXED001',
        'tracking_url' => null,
        'lines' => [['id' => $lampLine, 'quantity' => 1]],
    ], (string) Str::uuid());
    $order = mxOrderRow($orderId);
    expect($order['fulfillment_status'])->toBe('partially_fulfilled')
        ->and($order['download_status'])->toBe('entitled')
        ->and(DB::table('entitlements')->where('id', $entitlement->id)->value('status'))->toBe('active');
    $detail = app(ReadCustomerOrders::class)->detail($buyer, $orderId);
    expect($detail['fulfillment_status'])->toBe('partially_fulfilled')
        ->and($detail['download_status'])->toBe('entitled')
        ->and($detail['shipments'])->toHaveCount(1)
        ->and($detail['shipments'][0]['lines'][0]['quantity'])->toBe(1);
});

it('FulfillmentMixedOrder partial physical refund keeps digital access fully functional', function (): void {
    $catalog = mxCatalog("Refund-walk trail guide bytes.\n");
    $buyer = dlCustomer('mixed-refund@example.test');
    $paid = dlPaidOrder([
        ['variant' => $catalog['lamp'], 'quantity' => 2],
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::customer($buyer->id), $buyer->email, 'cs_mx_refund', 'pi_mx_refund');
    $orderId = $paid['order_id'];
    $guideLine = mxLineId($orderId, $catalog['guide']);
    $lampLine = mxLineId($orderId, $catalog['lamp']);
    $entitlementId = mxEntitlementId($orderId);
    expect(DB::table('entitlements')->where('id', $entitlementId)->value('status'))->toBe('active');

    // Staff refunds ONE of the two physical units; the digital line is not
    // part of the refund at all.
    entitlementFakeRefundSucceeded();
    $refund = app(RequestRefund::class)->request($catalog['owner'], $orderId, [
        'lines' => [['id' => $lampLine, 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'One lamp arrived damaged.',
    ], (string) Str::uuid());
    expect($refund['status'])->toBe('succeeded');

    $order = mxOrderRow($orderId);
    expect($order['financial_status'])->toBe('partially_refunded')
        ->and($order['fulfillment_status'])->toBe('unfulfilled')
        ->and($order['download_status'])->toBe('entitled')
        ->and((int) DB::table('order_lines')->where('id', $lampLine)->value('refunded_qty'))->toBe(1)
        ->and((int) DB::table('order_lines')->where('id', $guideLine)->value('refunded_qty'))->toBe(0)
        ->and(DB::table('entitlements')->where('id', $entitlementId)->value('status'))->toBe('active')
        ->and(DB::table('order_events')->where('order_id', $orderId)->where('type', 'entitlements_revoked')->count())->toBe(0)
        ->and(DB::table('order_events')->where('order_id', $orderId)->where('type', 'entitlements_suspended')->count())->toBe(0);

    // Access is functional, not just labelled: the area still lists the
    // file and a fresh grant starts and streams the guide after the
    // refund, spending the allowance normally.
    $this->actingAs($buyer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/downloads')->assertOk()->assertSee('Trail guide');
    expect(mxStreamGuide($buyer, $entitlementId))->toBe($catalog['contents']);
    expect((int) DB::table('entitlements')->where('id', $entitlementId)->value('downloads_used'))->toBe(1);

    // The customer order surface keeps the three projections apart.
    $detail = app(ReadCustomerOrders::class)->detail($buyer, $orderId);
    expect($detail['financial_status'])->toBe('partially_refunded')
        ->and($detail['fulfillment_status'])->toBe('unfulfilled')
        ->and($detail['download_status'])->toBe('entitled');

    // The un-refunded physical unit still ships on its own track, and
    // completing it leaves the digital half exactly as it was.
    app(CreateShipment::class)->create($catalog['owner'], $orderId, [
        'carrier' => 'UPS',
        'tracking_number' => '1ZMIXED002',
        'tracking_url' => null,
        'lines' => [['id' => $lampLine, 'quantity' => 1]],
    ], (string) Str::uuid());
    $order = mxOrderRow($orderId);
    expect($order['fulfillment_status'])->toBe('fulfilled')
        ->and($order['download_status'])->toBe('entitled')
        ->and($order['financial_status'])->toBe('partially_refunded')
        ->and(DB::table('entitlements')->where('id', $entitlementId)->value('status'))->toBe('active');
    $detail = app(ReadCustomerOrders::class)->detail($buyer, $orderId);
    expect($detail['fulfillment_status'])->toBe('fulfilled')
        ->and($detail['download_status'])->toBe('entitled')
        ->and($detail['shipments'])->toHaveCount(1);
});
