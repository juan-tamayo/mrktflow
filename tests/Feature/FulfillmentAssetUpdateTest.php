<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\CheckoutOrders\Actions\RequestRefund;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Fulfillment\Actions\AuthorizeDownload;
use App\Domain\Fulfillment\Actions\GrantAssetUpdate;
use App\Domain\Fulfillment\Actions\ResetDownloadLimit;
use App\Domain\Fulfillment\Data\DownloadPrincipal;
use App\Domain\Fulfillment\Exceptions\FulfillmentIdempotencyConflict;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Platform\Actions\CleanMedia;
use App\Domain\Platform\Actions\StoreMedia;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

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

function auStaff(string $role, string $email): StaffAccount
{
    $staff = new StaffAccount;
    $staff->forceFill([
        'name' => 'Staff '.$role, 'email' => $email,
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => $role, 'permissions' => StaffPermissions::forRole($role), 'is_active' => true, 'session_version' => 1,
    ])->save();

    return $staff;
}

/**
 * Adds the next version to the guide's digital asset without attaching
 * it, so only a deliberate staff grant can move a buyer onto it.
 *
 * @param  array<string, mixed>  $catalog
 * @return array{id: string, asset_id: string, version: int}
 */
function auNextVersion(array $catalog, ?string $contents = null): array
{
    $current = DB::table('digital_asset_versions')->where('id', $catalog['version_id'])->first();
    expect($current)->not->toBeNull();
    $assetId = (string) $current->digital_asset_id;
    $productId = (string) DB::table('digital_assets')->where('id', $assetId)->value('product_id');
    $created = app(VersionDigitalAsset::class)->version($catalog['owner'], [
        'product_id' => $productId,
        'digital_asset_id' => $assetId,
        'label' => 'Lighting guide',
        'file' => $contents === null ? catalogPrivateTextFile() : catalogPrivateTextFile('lighting-guide.txt', $contents),
    ]);

    return ['id' => $created['id'], 'asset_id' => $assetId, 'version' => $created['version']];
}

/**
 * Creates a second digital product with its own ready file version, so
 * a grant can be attempted against a version of a different asset.
 *
 * @param  array<string, mixed>  $catalog
 */
function auForeignVersionId(array $catalog): string
{
    $image = catalogReadyImage($catalog['owner']->id);
    $product = app(SaveProduct::class)->save($catalog['owner'], catalogDigitalProductInput($image, [
        'status' => 'published', 'title' => 'Field Notes', 'slug' => 'field-notes', 'sku' => 'NOTES-001',
    ]));
    $ready = app(VersionDigitalAsset::class)->version($catalog['owner'], [
        'product_id' => $product['id'],
        'label' => 'Field notes',
        'file' => catalogPrivateTextFile('field-notes.txt', "Unrelated field notes.\n"),
    ]);

    return $ready['id'];
}

function auPin(string $entitlementId): string
{
    return (string) DB::table('entitlements')->where('id', $entitlementId)->value('digital_asset_version_id');
}

function auTimelineCount(string $orderId, string $type): int
{
    return DB::table('order_events')->where('order_id', $orderId)->where('type', $type)->count();
}

function auAuditCount(string $prefix): int
{
    return DB::table('outbox_events')->where('event_type', 'platform.audit.recorded')
        ->where('intent_key', 'like', $prefix.'%')->count();
}

/**
 * @param  array<string, mixed>  $catalog
 * @return array{buyer: mixed, order_id: string, entitlement_id: string}
 */
function auBuyerOrder(array $catalog, string $email, string $session): array
{
    $buyer = dlCustomer($email);
    $paid = dlPaidOrder([
        ['variant' => $catalog['guide'], 'quantity' => 1],
    ], CartOwner::customer($buyer->id), $buyer->email, 'cs_'.$session, 'pi_'.$session);

    return ['buyer' => $buyer, 'order_id' => $paid['order_id'], 'entitlement_id' => dgEntitlementId($paid['order_id'])];
}

it('FulfillmentAssetUpdate grants a newer asset version to one buyer only, with audit, replay and conflict handling', function (): void {
    $catalog = entitlementCatalog();
    $granted = auBuyerOrder($catalog, 'au-grant-a@example.test', 'au_grant_a');
    $other = auBuyerOrder($catalog, 'au-grant-b@example.test', 'au_grant_b');
    $v2 = auNextVersion($catalog);
    expect($v2['version'])->toBe(2)
        ->and(auPin($granted['entitlement_id']))->toBe($catalog['version_id']);

    $key = (string) Str::uuid();
    $view = app(GrantAssetUpdate::class)->grant($catalog['owner'], $granted['order_id'], $granted['entitlement_id'], [
        'version_id' => $v2['id'],
        'reason' => 'Publisher released a corrected edition.',
    ], $key);
    expect($view['digital_asset_version_id'])->toBe($v2['id'])
        ->and($view['version'])->toBe(2)
        ->and($view['purchase_version'])->toBe(1);

    // Only the granted buyer's pin moved. The other buyer's entitlement,
    // both order lines' frozen purchase pins and the variant attachment
    // are untouched, so the original purchase version is preserved.
    expect(auPin($granted['entitlement_id']))->toBe($v2['id'])
        ->and(auPin($other['entitlement_id']))->toBe($catalog['version_id']);
    foreach ([$granted['order_id'], $other['order_id']] as $orderId) {
        expect(DB::table('order_lines')->where('order_id', $orderId)->value('digital_asset_version_id'))
            ->toBe($catalog['version_id']);
    }
    expect(DB::table('variant_digital_assets')->where('variant_id', $catalog['guide'])->value('digital_asset_version_id'))
        ->toBe($catalog['version_id']);
    expect(DB::table('entitlements')->where('id', $granted['entitlement_id'])->value('status'))->toBe('active');

    // One timeline entry carrying from/to and the reason, one audit.
    expect(auTimelineCount($granted['order_id'], 'entitlement_update_granted'))->toBe(1)
        ->and(auTimelineCount($other['order_id'], 'entitlement_update_granted'))->toBe(0)
        ->and(auAuditCount('audit:entitlement-update:'))->toBe(1);
    $payload = json_decode((string) DB::table('order_events')->where('order_id', $granted['order_id'])
        ->where('type', 'entitlement_update_granted')->value('payload'), true);
    expect($payload['entitlement_id'])->toBe($granted['entitlement_id'])
        ->and($payload['from_version_id'])->toBe($catalog['version_id'])
        ->and($payload['to_version_id'])->toBe($v2['id'])
        ->and($payload['from_version'])->toBe(1)
        ->and($payload['to_version'])->toBe(2)
        ->and($payload['reason'])->toBe('Publisher released a corrected edition.');

    // A replayed submission returns the existing state and records
    // nothing twice; the same key with a changed payload conflicts.
    $replay = app(GrantAssetUpdate::class)->grant($catalog['owner'], $granted['order_id'], $granted['entitlement_id'], [
        'version_id' => $v2['id'],
        'reason' => 'Publisher released a corrected edition.',
    ], $key);
    expect($replay['digital_asset_version_id'])->toBe($v2['id'])
        ->and(auTimelineCount($granted['order_id'], 'entitlement_update_granted'))->toBe(1)
        ->and(auAuditCount('audit:entitlement-update:'))->toBe(1);
    expect(fn () => app(GrantAssetUpdate::class)->grant($catalog['owner'], $granted['order_id'], $granted['entitlement_id'], [
        'version_id' => $v2['id'],
        'reason' => 'A different reason.',
    ], $key))->toThrow(FulfillmentIdempotencyConflict::class);
});

it('FulfillmentAssetUpdate rejects targets that are not a newer ready version of the same asset', function (): void {
    $catalog = entitlementCatalog();
    $buyer = auBuyerOrder($catalog, 'au-targets@example.test', 'au_targets');
    $v2 = auNextVersion($catalog);
    $grant = app(GrantAssetUpdate::class);
    $attempt = fn (array $input) => $grant->grant($catalog['owner'], $buyer['order_id'], $buyer['entitlement_id'], $input, (string) Str::uuid());

    // The version already pinned is not an update.
    expect(fn () => $attempt(['version_id' => $catalog['version_id'], 'reason' => 'Same version.']))
        ->toThrow(ValidationException::class);
    // A missing reason is not a grant.
    expect(fn () => $attempt(['version_id' => $v2['id'], 'reason' => '']))
        ->toThrow(ValidationException::class);
    // A quarantined (not ready) newer version cannot be granted.
    DB::table('digital_asset_versions')->where('id', $v2['id'])->update(['status' => 'quarantined']);
    expect(fn () => $attempt(['version_id' => $v2['id'], 'reason' => 'Quarantined file.']))
        ->toThrow(ValidationException::class);
    DB::table('digital_asset_versions')->where('id', $v2['id'])->update(['status' => 'ready']);

    // A ready version of a different asset is not this buyer's file.
    $foreignVersion = auForeignVersionId($catalog);
    expect(fn () => $attempt(['version_id' => $foreignVersion, 'reason' => 'Wrong asset.']))
        ->toThrow(ValidationException::class);

    // After moving to v2, v1 is no longer newer: no silent downgrade.
    $attempt(['version_id' => $v2['id'], 'reason' => 'Upgrade to the corrected edition.']);
    expect(auPin($buyer['entitlement_id']))->toBe($v2['id']);
    expect(fn () => $attempt(['version_id' => $catalog['version_id'], 'reason' => 'Roll back.']))
        ->toThrow(ValidationException::class);
    expect(auTimelineCount($buyer['order_id'], 'entitlement_update_granted'))->toBe(1);

    // Staff without fulfillment.manage cannot grant at all.
    $editor = auStaff('content_editor', 'au-editor@example.test');
    expect(fn () => $grant->grant($editor, $buyer['order_id'], $buyer['entitlement_id'], [
        'version_id' => $v2['id'], 'reason' => 'No permission.',
    ], (string) Str::uuid()))->toThrow(AuthorizationException::class);
});

it('FulfillmentAssetUpdate refuses to touch a revoked entitlement', function (): void {
    $catalog = entitlementCatalog();
    $buyer = auBuyerOrder($catalog, 'au-revoked@example.test', 'au_revoked');
    $v2 = auNextVersion($catalog);
    $lineId = (string) DB::table('order_lines')->where('order_id', $buyer['order_id'])->value('id');

    entitlementFakeRefundSucceeded();
    $refund = app(RequestRefund::class)->request($catalog['owner'], $buyer['order_id'], [
        'lines' => [['id' => $lineId, 'quantity' => 1]],
        'shipping_amount' => 0,
        'reason' => 'Full refund before any staff update.',
    ], (string) Str::uuid());
    expect($refund['status'])->toBe('succeeded')
        ->and(DB::table('entitlements')->where('id', $buyer['entitlement_id'])->value('status'))->toBe('revoked');

    // Neither deliberate staff action can resurrect or mutate the
    // terminal row; its pin and counters stay as history.
    expect(fn () => app(GrantAssetUpdate::class)->grant($catalog['owner'], $buyer['order_id'], $buyer['entitlement_id'], [
        'version_id' => $v2['id'], 'reason' => 'Try to resurrect.',
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(fn () => app(ResetDownloadLimit::class)->reset($catalog['owner'], $buyer['order_id'], $buyer['entitlement_id'], [
        'reason' => 'Try to reset.',
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(auPin($buyer['entitlement_id']))->toBe($catalog['version_id'])
        ->and(auTimelineCount($buyer['order_id'], 'entitlement_update_granted'))->toBe(0)
        ->and(auTimelineCount($buyer['order_id'], 'entitlement_limit_reset'))->toBe(0);
});

it('FulfillmentAssetUpdate resets the download allowance with audit, bounds, replay and conflict handling', function (): void {
    $catalog = entitlementCatalog();
    $buyer = auBuyerOrder($catalog, 'au-reset@example.test', 'au_reset');
    $entitlementId = $buyer['entitlement_id'];
    $principal = DownloadPrincipal::account($buyer['buyer'], 'session-au-reset');
    $downloads = app(AuthorizeDownload::class);
    foreach ([1, 2] as $ignored) {
        $issued = $downloads->issue($principal, $entitlementId);
        $downloads->start($principal, $issued['grant_id'], $issued['token']);
    }
    $before = DB::table('entitlements')->where('id', $entitlementId)->first();
    expect((int) $before->downloads_used)->toBe(2)->and((int) $before->download_limit)->toBe(5);

    $key = (string) Str::uuid();
    $view = app(ResetDownloadLimit::class)->reset($catalog['owner'], $buyer['order_id'], $entitlementId, [
        'download_limit' => 10,
        'reason' => 'Customer lost the first downloads to a disk failure.',
    ], $key);
    expect($view['downloads_used'])->toBe(0)
        ->and($view['download_limit'])->toBe(10)
        ->and($view['downloads_remaining'])->toBe(10);
    expect(auTimelineCount($buyer['order_id'], 'entitlement_limit_reset'))->toBe(1)
        ->and(auAuditCount('audit:entitlement-limit-reset:'))->toBe(1);
    $payload = json_decode((string) DB::table('order_events')->where('order_id', $buyer['order_id'])
        ->where('type', 'entitlement_limit_reset')->value('payload'), true);
    expect($payload['entitlement_id'])->toBe($entitlementId)
        ->and($payload['previous_limit'])->toBe(5)
        ->and($payload['download_limit'])->toBe(10)
        ->and($payload['previous_used'])->toBe(2)
        ->and($payload['reason'])->toBe('Customer lost the first downloads to a disk failure.');

    // Replay returns the state without a second record; a changed
    // payload under the same key conflicts.
    $replay = app(ResetDownloadLimit::class)->reset($catalog['owner'], $buyer['order_id'], $entitlementId, [
        'download_limit' => 10,
        'reason' => 'Customer lost the first downloads to a disk failure.',
    ], $key);
    expect($replay['downloads_used'])->toBe(0)
        ->and(auTimelineCount($buyer['order_id'], 'entitlement_limit_reset'))->toBe(1);
    expect(fn () => app(ResetDownloadLimit::class)->reset($catalog['owner'], $buyer['order_id'], $entitlementId, [
        'download_limit' => 9,
        'reason' => 'Customer lost the first downloads to a disk failure.',
    ], $key))->toThrow(FulfillmentIdempotencyConflict::class);

    // A reset that changes nothing is an error, and out-of-bounds
    // limits are rejected before any state moves.
    expect(fn () => app(ResetDownloadLimit::class)->reset($catalog['owner'], $buyer['order_id'], $entitlementId, [
        'reason' => 'Nothing to reset.',
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
    foreach ([0, 1001] as $limit) {
        expect(fn () => app(ResetDownloadLimit::class)->reset($catalog['owner'], $buyer['order_id'], $entitlementId, [
            'download_limit' => $limit, 'reason' => 'Out of bounds.',
        ], (string) Str::uuid()))->toThrow(ValidationException::class);
    }
    $after = DB::table('entitlements')->where('id', $entitlementId)->first();
    expect((int) $after->downloads_used)->toBe(0)->and((int) $after->download_limit)->toBe(10)
        ->and(auTimelineCount($buyer['order_id'], 'entitlement_limit_reset'))->toBe(1);

    // The reset allowance is real: the buyer can spend again.
    $issued = $downloads->issue($principal, $entitlementId);
    $downloads->start($principal, $issued['grant_id'], $issued['token']);
    expect((int) DB::table('entitlements')->where('id', $entitlementId)->value('downloads_used'))->toBe(1);
});

it('FulfillmentAssetUpdate the granted buyer streams the new version while the other buyer keeps the purchased one', function (): void {
    $v1Contents = str_repeat('ORIGINAL-EDITION ', 40);
    $catalog = dgCatalog($v1Contents);
    $granted = auBuyerOrder($catalog, 'au-stream-a@example.test', 'au_stream_a');
    $other = auBuyerOrder($catalog, 'au-stream-b@example.test', 'au_stream_b');
    $v2Contents = str_repeat('CORRECTED-EDITION ', 40);
    $v2 = auNextVersion($catalog, $v2Contents);
    expect($v2['version'])->toBe(2);

    app(GrantAssetUpdate::class)->grant($catalog['owner'], $granted['order_id'], $granted['entitlement_id'], [
        'version_id' => $v2['id'],
        'reason' => 'Corrected edition released.',
    ], (string) Str::uuid());

    $stream = function (array $purchase, string $session): string {
        $principal = DownloadPrincipal::account($purchase['buyer'], $session);
        $downloads = app(AuthorizeDownload::class);
        $issued = $downloads->issue($principal, $purchase['entitlement_id']);
        $started = $downloads->start($principal, $issued['grant_id'], $issued['token']);
        $transfer = DB::table('download_transfers')->where('id', $started['transfer_id'])->first();
        expect($transfer)->not->toBeNull();
        $this->actingAs($purchase['buyer'], 'customer')->withSession(['customer_session_version' => 1]);
        $response = $this->get('/account/download-transfers/'.$started['transfer_id']);
        $response->assertOk();

        return $response->streamedContent();
    };
    expect($stream($granted, 'session-au-stream-a'))->toBe($v2Contents)
        ->and($stream($other, 'session-au-stream-b'))->toBe($v1Contents);

    // The original version row and its private file survive the grant,
    // and both buyers' purchase records still pin the purchased version.
    $v1 = DB::table('digital_asset_versions')->where('id', $catalog['version_id'])->first();
    expect($v1)->not->toBeNull()->and($v1->status)->toBe('ready');
    $media = DB::table('media_assets')->where('id', $v1->media_asset_id)->first();
    expect($media)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $media->original_key))->toBeTrue();
    foreach ([$granted['order_id'], $other['order_id']] as $orderId) {
        expect(DB::table('order_lines')->where('order_id', $orderId)->value('digital_asset_version_id'))
            ->toBe($catalog['version_id']);
    }
});

it('FulfillmentAssetUpdate cleanup never purges a referenced private file but still purges unreferenced drafts', function (): void {
    $catalog = entitlementCatalog();
    $version = DB::table('digital_asset_versions')->where('id', $catalog['version_id'])->first();
    $mediaId = (string) $version->media_asset_id;
    $originalKey = (string) DB::table('media_assets')->where('id', $mediaId)->value('original_key');
    expect(Storage::disk('local')->exists($originalKey))->toBeTrue();
    // Make the referenced media look exactly like an abandoned draft.
    DB::table('media_assets')->where('id', $mediaId)->update([
        'status' => 'failed', 'error_code' => 'fixture_failure',
        'created_at' => now()->subHours(25), 'updated_at' => now()->subHours(25), 'cleanup_checked_at' => null,
    ]);
    // An unreferenced abandoned draft as the control.
    $draft = app(StoreMedia::class)->store(
        UploadedFile::fake()->createWithContent('draft.txt', 'fixture'),
        'private_file',
        new MediaFixtureAuthorization,
    );
    DB::table('media_assets')->where('id', $draft)->update([
        'status' => 'staging', 'created_at' => now()->subHours(25), 'updated_at' => now()->subHours(25),
    ]);

    app(CleanMedia::class)->clean();

    expect(Storage::disk('local')->exists($originalKey))->toBeTrue()
        ->and(DB::table('media_assets')->where('id', $mediaId)->value('purged_at'))->toBeNull();
    expect(Storage::disk('local')->exists('media/'.$draft.'/original'))->toBeFalse()
        ->and(DB::table('media_assets')->where('id', $draft)->value('purged_at'))->not->toBeNull();
});

it('FulfillmentAssetUpdate HTTP requires fulfillment permission and fresh 2FA and drives both actions', function (): void {
    $catalog = entitlementCatalog();
    $purchase = auBuyerOrder($catalog, 'au-http@example.test', 'au_http');
    $orderId = $purchase['order_id'];
    $entitlementId = $purchase['entitlement_id'];
    $v2 = auNextVersion($catalog);
    $owner = $catalog['owner'];
    $updateUrl = '/admin/orders/'.$orderId.'/entitlements/'.$entitlementId.'/update';
    $resetUrl = '/admin/orders/'.$orderId.'/entitlements/'.$entitlementId.'/reset-limit';

    // Stale two-factor state cannot spend the grant.
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version, 'staff_two_factor_verified' => true])
        ->from('/admin/orders/'.$orderId)
        ->post($updateUrl, [
            'version_id' => $v2['id'],
            'reason' => 'HTTP grant.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/admin/two-factor');
    expect(auPin($entitlementId))->toBe($catalog['version_id']);

    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/orders/'.$orderId)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('OrderDetail')
        ->where('canManageDownloads', true)
        ->where('order.downloads.0.id', $entitlementId)
        ->where('order.downloads.0.version', 1)
        ->where('order.downloads.0.purchase_version', 1)
        ->where('order.downloads.0.status', 'active')
        ->where('order.downloads.0.available_versions.0.id', $v2['id'])
        ->where('order.downloads.0.available_versions.0.version', 2));

    // Validation failures come back as errors, not silent success.
    $this->from('/admin/orders/'.$orderId)
        ->post($updateUrl, ['version_id' => $v2['id'], 'idempotency_key' => (string) Str::uuid()])
        ->assertRedirect('/admin/orders/'.$orderId)->assertSessionHasErrors('reason');
    expect(auPin($entitlementId))->toBe($catalog['version_id']);

    $this->from('/admin/orders/'.$orderId)
        ->post($updateUrl, [
            'version_id' => $v2['id'],
            'reason' => 'HTTP grant.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/admin/orders/'.$orderId)->assertSessionHas('order_download_saved', true);
    expect(auPin($entitlementId))->toBe($v2['id']);

    // Spend one download, then reset it over HTTP.
    $principal = DownloadPrincipal::account($purchase['buyer'], 'session-au-http');
    $downloads = app(AuthorizeDownload::class);
    $issued = $downloads->issue($principal, $entitlementId);
    $downloads->start($principal, $issued['grant_id'], $issued['token']);
    expect((int) DB::table('entitlements')->where('id', $entitlementId)->value('downloads_used'))->toBe(1);
    $this->from('/admin/orders/'.$orderId)
        ->post($resetUrl, [
            'reason' => 'HTTP reset.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/admin/orders/'.$orderId)->assertSessionHas('order_download_saved', true);
    expect((int) DB::table('entitlements')->where('id', $entitlementId)->value('downloads_used'))->toBe(0);

    // Staff without fulfillment.manage are refused.
    $editor = auStaff('content_editor', 'au-editor-http@example.test');
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor))
        ->postJson($updateUrl, [
            'version_id' => $v2['id'],
            'reason' => 'No permission.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
    $this->get('/')->assertOk();
});
