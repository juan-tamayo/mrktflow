<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Fulfillment\Actions\AuthorizeDownload;
use App\Domain\Fulfillment\Actions\ReadDownloads;
use App\Domain\Fulfillment\Actions\VerifyGuestOrder;
use App\Domain\Fulfillment\Data\DownloadPrincipal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;

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
 * A digital catalog whose downloadable file has caller-chosen contents, so
 * Range slices can be asserted byte for byte.
 *
 * @return array<string, mixed>
 */
function dgCatalog(string $contents): array
{
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['amount' => 300])]])],
    ]);
    pricingSavedTaxRules($owner);
    $image = catalogReadyImage($owner->id);
    $guide = app(SaveProduct::class)->save($owner, catalogDigitalProductInput($image, [
        'status' => 'published', 'price_amount' => 500,
    ]));
    $guideVariant = (string) DB::table('product_variants')->where('product_id', $guide['id'])->value('id');
    $ready = app(VersionDigitalAsset::class)->version($owner, [
        'product_id' => $guide['id'],
        'label' => 'Field guide',
        'file' => catalogPrivateTextFile('field-guide.txt', $contents),
    ]);
    app(VersionDigitalAsset::class)->attach($owner, [
        'product_id' => $guide['id'],
        'variant_id' => $guideVariant,
        'version_ids' => [$ready['id']],
    ]);

    return ['owner' => $owner, 'guide' => $guideVariant, 'version_id' => $ready['id'], 'contents' => $contents];
}

function dgEntitlementId(string $orderId): string
{
    return (string) DB::table('entitlements')->where('order_id', $orderId)->value('id');
}

it('FulfillmentDownload issues a digest-only five-minute start token that spends no allowance', function (): void {
    $catalog = dgCatalog(str_repeat('0123456789', 100));
    $buyer = dlCustomer('grant-issue@example.test');
    $paid = dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::customer($buyer->id), $buyer->email, 'cs_dg_issue', 'pi_dg_issue');
    $entitlementId = dgEntitlementId($paid['order_id']);
    $principal = DownloadPrincipal::account($buyer, 'session-issue');

    $issued = app(AuthorizeDownload::class)->issue($principal, $entitlementId);
    expect($issued['token'])->toMatch('/^[a-f0-9]{64}$/D')
        ->and($issued['expires_at']->equalTo(Carbon::parse('2026-10-16T18:05:00Z')))->toBeTrue();
    $grant = DB::table('download_grants')->where('id', $issued['grant_id'])->first();
    expect($grant)->not->toBeNull()
        ->and($grant->token_digest)->toBe(hash('sha256', $issued['token']))
        ->and($grant->token_digest)->not->toBe($issued['token'])
        ->and($grant->consumed_at)->toBeNull()
        ->and($grant->customer_id)->toBe($buyer->id)
        ->and($grant->order_id)->toBe($paid['order_id']);
    // Issuing alone spends nothing and creates no transfer.
    expect((int) DB::table('entitlements')->where('id', $entitlementId)->value('downloads_used'))->toBe(0)
        ->and(DB::table('download_transfers')->count())->toBe(0);

    // A second issue leaves the first token live: the allowance, not the
    // token count, is the bound.
    $second = app(AuthorizeDownload::class)->issue($principal, $entitlementId);
    expect($second['grant_id'])->not->toBe($issued['grant_id'])
        ->and(DB::table('download_grants')->where('entitlement_id', $entitlementId)->whereNull('consumed_at')->count())->toBe(2);

    // Cross-customer issuance reveals nothing.
    $stranger = dlCustomer('grant-stranger@example.test');
    expect(fn () => app(AuthorizeDownload::class)->issue(DownloadPrincipal::account($stranger, 'session-x'), $entitlementId))
        ->toThrow(NotFoundHttpException::class);
});

it('FulfillmentDownload start POST consumes the token once and the owner status recovers a lost response', function (): void {
    $catalog = dgCatalog(str_repeat('0123456789', 100));
    $buyer = dlCustomer('grant-start@example.test');
    $paid = dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::customer($buyer->id), $buyer->email, 'cs_dg_start', 'pi_dg_start');
    $entitlementId = dgEntitlementId($paid['order_id']);

    $this->actingAs($buyer, 'customer')->withSession(['customer_session_version' => 1]);
    $area = $this->get('/account/downloads');
    $area->assertOk()->assertSee('/account/downloads/'.$entitlementId.'/grants', false)
        ->assertSee('5 of 5 downloads remaining');
    $issueResponse = $this->post('/account/downloads/'.$entitlementId.'/grants');
    $issueResponse->assertRedirect();
    expect(str_contains((string) $issueResponse->headers->get('Location'), '/account/download-grants/'))->toBeTrue();
    $grantId = (string) DB::table('download_grants')->where('entitlement_id', $entitlementId)->value('id');
    $grantUrl = '/account/download-grants/'.$grantId;

    // Scanner/prefetch safety: GETs render state only. The start path is
    // POST-only and a status read never consumes the token.
    $this->get($grantUrl.'/start')->assertStatus(405);
    $status = $this->get($grantUrl);
    $status->assertOk()->assertSee('Field guide');
    expect(DB::table('download_grants')->where('id', $grantId)->value('consumed_at'))->toBeNull()
        ->and(DB::table('download_transfers')->count())->toBe(0);
    preg_match('/name="token" value="([a-f0-9]{64})"/', (string) $status->getContent(), $match);
    expect($match[1] ?? null)->not->toBeNull();
    $token = $match[1];

    $started = $this->post($grantUrl.'/start', ['token' => $token]);
    $started->assertRedirect($grantUrl);
    $grant = DB::table('download_grants')->where('id', $grantId)->first();
    $transfer = DB::table('download_transfers')->where('grant_id', $grantId)->first();
    expect($grant->consumed_at)->not->toBeNull()
        ->and($grant->token_digest)->toBeNull()
        ->and($transfer)->not->toBeNull()
        ->and($transfer->id)->toBe($grant->transfer_id)
        ->and($transfer->customer_id)->toBe($buyer->id)
        ->and($transfer->digital_asset_version_id)->toBe($catalog['version_id'])
        ->and($transfer->status)->toBe('active')
        ->and(Carbon::parse($transfer->expires_at)->equalTo(Carbon::parse('2026-10-16T19:00:00Z')))->toBeTrue()
        ->and((int) DB::table('entitlements')->where('id', $entitlementId)->value('downloads_used'))->toBe(1)
        ->and(DB::table('order_events')->where('order_id', $paid['order_id'])->where('type', 'download_started')->count())->toBe(1);

    // The lost-response recovery: the owner-only status page now exposes
    // the transfer stream without any new spend.
    $this->get($grantUrl)->assertOk()
        ->assertSee('/account/download-transfers/'.$transfer->id, false);
    // Replaying the start POST cannot create another session or spend again.
    $this->post($grantUrl.'/start', ['token' => $token])->assertNotFound();
    expect(DB::table('download_transfers')->count())->toBe(1)
        ->and((int) DB::table('entitlements')->where('id', $entitlementId)->value('downloads_used'))->toBe(1);

    // The transfer outlives the five-minute start window...
    Carbon::setTestNow(Carbon::parse('2026-10-16T18:10:00Z')->utc());
    $stream = $this->get('/account/download-transfers/'.$transfer->id);
    $stream->assertOk();
    expect($stream->streamedContent())->toBe($catalog['contents']);
    // ...but not its own one-hour lifetime.
    Carbon::setTestNow(Carbon::parse('2026-10-16T19:00:01Z')->utc());
    $this->get('/account/download-transfers/'.$transfer->id)->assertGone();
});

it('FulfillmentDownload streams bounded ranges and resume spends no further allowance', function (): void {
    $contents = str_repeat('0123456789', 100);
    $catalog = dgCatalog($contents);
    $buyer = dlCustomer('grant-range@example.test');
    $paid = dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::customer($buyer->id), $buyer->email, 'cs_dg_range', 'pi_dg_range');
    $entitlementId = dgEntitlementId($paid['order_id']);
    $principal = DownloadPrincipal::account($buyer, 'session-range');
    $issued = app(AuthorizeDownload::class)->issue($principal, $entitlementId);
    $started = app(AuthorizeDownload::class)->start($principal, $issued['grant_id'], $issued['token']);
    $url = '/account/download-transfers/'.$started['transfer_id'];

    $this->actingAs($buyer, 'customer')->withSession(['customer_session_version' => 1]);
    $full = $this->get($url);
    $full->assertOk()->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Length', '1000')
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    expect($full->streamedContent())->toBe($contents);

    $slice = $this->call('GET', $url, [], [], [], ['HTTP_RANGE' => 'bytes=100-199']);
    $slice->assertStatus(206)->assertHeader('Content-Range', 'bytes 100-199/1000')
        ->assertHeader('Content-Length', '100');
    expect($slice->streamedContent())->toBe(substr($contents, 100, 100));

    $suffix = $this->call('GET', $url, [], [], [], ['HTTP_RANGE' => 'bytes=-50']);
    $suffix->assertStatus(206)->assertHeader('Content-Range', 'bytes 950-999/1000');
    expect($suffix->streamedContent())->toBe(substr($contents, 950));

    $open = $this->call('GET', $url, [], [], [], ['HTTP_RANGE' => 'bytes=990-']);
    $open->assertStatus(206)->assertHeader('Content-Range', 'bytes 990-999/1000');
    expect($open->streamedContent())->toBe(substr($contents, 990));

    $clamped = $this->call('GET', $url, [], [], [], ['HTTP_RANGE' => 'bytes=995-5000']);
    $clamped->assertStatus(206)->assertHeader('Content-Range', 'bytes 995-999/1000');
    expect($clamped->streamedContent())->toBe(substr($contents, 995));

    $this->call('GET', $url, [], [], [], ['HTTP_RANGE' => 'bytes=5000-6000'])
        ->assertStatus(416)->assertHeader('Content-Range', 'bytes */1000');
    $multi = $this->call('GET', $url, [], [], [], ['HTTP_RANGE' => 'bytes=0-9,20-29']);
    $multi->assertOk();
    expect($multi->streamedContent())->toBe($contents);
    $garbage = $this->call('GET', $url, [], [], [], ['HTTP_RANGE' => 'items=0-9']);
    $garbage->assertOk();
    expect($garbage->streamedContent())->toBe($contents);

    // All that resuming spent nothing beyond the original start.
    expect((int) DB::table('entitlements')->where('id', $entitlementId)->value('downloads_used'))->toBe(1)
        ->and(DB::table('download_transfers')->where('entitlement_id', $entitlementId)->count())->toBe(1);
});

it('FulfillmentDownload rejects new streams after revocation and for other principals', function (): void {
    $catalog = dgCatalog(str_repeat('0123456789', 100));
    $buyer = dlCustomer('grant-revoke@example.test');
    $paid = dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::customer($buyer->id), $buyer->email, 'cs_dg_revoke', 'pi_dg_revoke');
    $entitlementId = dgEntitlementId($paid['order_id']);
    $principal = DownloadPrincipal::account($buyer, 'session-revoke');
    $issued = app(AuthorizeDownload::class)->issue($principal, $entitlementId);
    $started = app(AuthorizeDownload::class)->start($principal, $issued['grant_id'], $issued['token']);
    $url = '/account/download-transfers/'.$started['transfer_id'];

    $this->actingAs($buyer, 'customer')->withSession(['customer_session_version' => 1]);
    $ok = $this->get($url);
    $ok->assertOk();
    expect($ok->streamedContent())->toBe($catalog['contents']);

    // Revoke-before-resume: flipping the entitlement (the state T052 will
    // set) blocks every new stream, full or ranged. Bytes already sent are
    // gone; nothing here claims otherwise.
    DB::table('entitlements')->where('id', $entitlementId)->update(['status' => 'revoked']);
    $this->get($url)->assertForbidden();
    $this->call('GET', $url, [], [], [], ['HTTP_RANGE' => 'bytes=0-9'])->assertForbidden();
    DB::table('entitlements')->where('id', $entitlementId)->update(['status' => 'suspended']);
    $this->get($url)->assertForbidden();
    DB::table('entitlements')->where('id', $entitlementId)->update(['status' => 'active']);
    DB::table('download_transfers')->where('id', $started['transfer_id'])->update(['status' => 'revoked']);
    $this->get($url)->assertForbidden();

    // Cross-principal access reveals nothing anywhere in the flow.
    $stranger = dlCustomer('grant-nosy@example.test');
    $this->actingAs($stranger, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get($url)->assertNotFound();
    $this->get('/account/download-grants/'.$issued['grant_id'])->assertNotFound();
    $this->post('/account/download-grants/'.$issued['grant_id'].'/start', ['token' => $issued['token']])->assertNotFound();
    $this->post('/account/downloads/'.$entitlementId.'/grants')->assertNotFound();
});

it('FulfillmentDownload allows only one concurrent stream per transfer', function (): void {
    $catalog = dgCatalog(str_repeat('0123456789', 100));
    $buyer = dlCustomer('grant-lease@example.test');
    $paid = dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::customer($buyer->id), $buyer->email, 'cs_dg_lease', 'pi_dg_lease');
    $entitlementId = dgEntitlementId($paid['order_id']);
    $principal = DownloadPrincipal::account($buyer, 'session-lease');
    $issued = app(AuthorizeDownload::class)->issue($principal, $entitlementId);
    $started = app(AuthorizeDownload::class)->start($principal, $issued['grant_id'], $issued['token']);
    $url = '/account/download-transfers/'.$started['transfer_id'];

    $this->actingAs($buyer, 'customer')->withSession(['customer_session_version' => 1]);
    // A live lease held by a different stream blocks a second one.
    DB::table('download_transfers')->where('id', $started['transfer_id'])->update([
        'stream_lease_owner' => hash('sha256', 'someone-else'),
        'stream_lease_expires_at' => now()->addMinutes(2),
    ]);
    $this->get($url)->assertStatus(409);
    // Once that lease lapses the owner streams again.
    DB::table('download_transfers')->where('id', $started['transfer_id'])->update([
        'stream_lease_expires_at' => now()->subSecond(),
    ]);
    $resumed = $this->get($url);
    $resumed->assertOk();
    expect($resumed->streamedContent())->toBe($catalog['contents']);
    // The lease is released when the response completes.
    $transfer = DB::table('download_transfers')->where('id', $started['transfer_id'])->first();
    expect($transfer->stream_lease_owner)->toBeNull();
});

it('FulfillmentDownload serializes racing starts so the last allowance creates exactly one transfer', function (): void {
    $catalog = dgCatalog(str_repeat('0123456789', 100));
    $buyer = dlCustomer('grant-race@example.test');
    $paid = dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::customer($buyer->id), $buyer->email, 'cs_dg_race', 'pi_dg_race');
    $entitlementId = dgEntitlementId($paid['order_id']);
    $authorize = app(AuthorizeDownload::class);
    $principal = DownloadPrincipal::account($buyer, 'session-race');
    // Spend four of the five allowances through the real flow.
    for ($i = 0; $i < 4; $i++) {
        $grant = $authorize->issue($principal, $entitlementId);
        $authorize->start($principal, $grant['grant_id'], $grant['token']);
    }
    expect((int) DB::table('entitlements')->where('id', $entitlementId)->value('downloads_used'))->toBe(4);
    $first = $authorize->issue($principal, $entitlementId);
    $second = $authorize->issue($principal, $entitlementId);

    $processes = [];
    $readyFiles = [];
    $start = function (array $grant) use ($buyer, &$processes, &$readyFiles): Process {
        $id = (string) Str::uuid();
        $readyFiles[] = base_path('.runtime/download-'.$id.'.ready');
        $process = new Process(
            [base_path('bin/php'), base_path('tests/Fixtures/download-start-race.php'), $id, '--env=testing'],
            base_path(),
            ['APP_ENV' => 'testing'],
        );
        $process->setInput(json_encode([
            'customer_id' => $buyer->id,
            'order_id' => null,
            'grant_id' => $grant['grant_id'],
            'token' => $grant['token'],
            'session_id' => 'race-session',
            'now' => '2026-10-16T18:00:00Z',
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(20)->start();
        $processes[] = $process;

        return $process;
    };
    try {
        DB::beginTransaction();
        DB::table('entitlements')->where('id', $entitlementId)->lockForUpdate()->first();
        $start($first);
        $start($second);
        $deadline = microtime(true) + 8;
        while ((! is_file($readyFiles[0]) || ! is_file($readyFiles[1])) && microtime(true) < $deadline) {
            expect($processes[0]->isRunning() && $processes[1]->isRunning())->toBeTrue();
            usleep(20000);
        }
        expect(is_file($readyFiles[0]) && is_file($readyFiles[1]))->toBeTrue();
        DB::commit();
        $statuses = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0);
            $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $statuses[] = $payload['status'];
        }
        sort($statuses);
        expect($statuses)->toBe(['denied', 'started'])
            ->and((int) DB::table('entitlements')->where('id', $entitlementId)->value('downloads_used'))->toBe(5)
            ->and(DB::table('download_transfers')->where('entitlement_id', $entitlementId)->count())->toBe(5);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        foreach ($readyFiles as $ready) {
            if (is_file($ready)) {
                unlink($ready);
            }
        }
    }
});

it('FulfillmentDownload refuses starts past the allowance and past the token window', function (): void {
    $catalog = dgCatalog(str_repeat('0123456789', 100));
    $buyer = dlCustomer('grant-spent@example.test');
    $paid = dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::customer($buyer->id), $buyer->email, 'cs_dg_spent', 'pi_dg_spent');
    $entitlementId = dgEntitlementId($paid['order_id']);
    $authorize = app(AuthorizeDownload::class);
    $principal = DownloadPrincipal::account($buyer, 'session-spent');
    for ($i = 0; $i < 5; $i++) {
        $grant = $authorize->issue($principal, $entitlementId);
        $authorize->start($principal, $grant['grant_id'], $grant['token']);
    }
    // Issuing is still free; the sixth start is refused and spends nothing.
    $sixth = $authorize->issue($principal, $entitlementId);
    $this->actingAs($buyer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->post('/account/download-grants/'.$sixth['grant_id'].'/start', ['token' => $sixth['token']])
        ->assertForbidden();
    expect(DB::table('download_transfers')->where('entitlement_id', $entitlementId)->count())->toBe(5)
        ->and((int) DB::table('entitlements')->where('id', $entitlementId)->value('downloads_used'))->toBe(5);
    $area = app(ReadDownloads::class)->forCustomer($buyer);
    expect($area['orders'][0]['downloads'][0]['downloads_remaining'])->toBe(0);
    $this->get('/account/downloads')->assertOk()
        ->assertSee('0 of 5 downloads remaining')
        ->assertDontSee('/account/downloads/'.$entitlementId.'/grants', false);

    // An expired start token (six minutes on) is dead and spends nothing.
    $late = $authorize->issue($principal, $entitlementId);
    Carbon::setTestNow(Carbon::parse('2026-10-16T18:06:00Z')->utc());
    $this->post('/account/download-grants/'.$late['grant_id'].'/start', ['token' => $late['token']])
        ->assertNotFound();
    expect(DB::table('download_grants')->where('id', $late['grant_id'])->value('consumed_at'))->toBeNull();
});

it('FulfillmentDownload guest flow streams only inside the verified single-order scope', function (): void {
    $catalog = dgCatalog(str_repeat('0123456789', 100));
    $paid = dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::guest('guest-dg-flow'), 'guest@example.test', 'cs_dg_guest', 'pi_dg_guest');
    $orderId = $paid['order_id'];
    $entitlementId = dgEntitlementId($orderId);
    $issueUrl = '/orders/'.$orderId.'/downloads/'.$entitlementId.'/grants';

    // No verified session grant: nothing in the flow is reachable.
    $this->post($issueUrl)->assertNotFound();
    $this->get('/orders/'.$orderId.'/download-transfers/'.(string) Str::uuid())->assertNotFound();

    $access = app(VerifyGuestOrder::class)->requestAccess($orderId, 'guest@example.test');
    $this->post('/orders/download-access/confirm', ['challenge_token' => $access['token']])
        ->assertRedirect('/orders/'.$orderId.'/downloads');
    $this->get('/orders/'.$orderId.'/downloads')->assertOk()->assertSee($issueUrl, false);

    $guestIssue = $this->post($issueUrl);
    $guestIssue->assertRedirect();
    expect(str_contains((string) $guestIssue->headers->get('Location'), '/orders/'.$orderId.'/download-grants/'))->toBeTrue();
    $grantId = (string) DB::table('download_grants')->where('entitlement_id', $entitlementId)->value('id');
    $grantUrl = '/orders/'.$orderId.'/download-grants/'.$grantId;
    $status = $this->get($grantUrl);
    $status->assertOk();
    preg_match('/name="token" value="([a-f0-9]{64})"/', (string) $status->getContent(), $match);
    expect($match[1] ?? null)->not->toBeNull();
    $this->post($grantUrl.'/start', ['token' => $match[1]])->assertRedirect($grantUrl);
    $transferId = (string) DB::table('download_transfers')->where('grant_id', $grantId)->value('id');
    $this->get($grantUrl)->assertOk()->assertSee('/orders/'.$orderId.'/download-transfers/'.$transferId, false);
    $stream = $this->get('/orders/'.$orderId.'/download-transfers/'.$transferId);
    $stream->assertOk();
    expect($stream->streamedContent())->toBe($catalog['contents']);

    // The same transfer under the account routes belongs to nobody there.
    $buyer = dlCustomer('grant-guest-cross@example.test');
    $this->actingAs($buyer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/download-transfers/'.$transferId)->assertNotFound();
    $this->get('/account/download-grants/'.$grantId)->assertNotFound();
});
