<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\AllocateInventory;
use App\Domain\Catalog\Actions\SaveVariants;
use App\Domain\Catalog\Data\InventoryHold;
use App\Domain\Catalog\Data\InventoryLine;
use App\Domain\Catalog\Data\InventoryMutation;
use App\Domain\Platform\Actions\UpdateDeliveryPreference;
use App\Domain\Platform\Support\UnsubscribeTokens;
use App\Domain\ShoppingTools\Actions\DeliverWatchAlert;
use App\Domain\ShoppingTools\Actions\EvaluateWatchEvent;
use App\Domain\ShoppingTools\Actions\SubscribeToWatch;
use App\Jobs\SendWatchAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-16T18:00:00Z')->utc());
    config(['app.url' => 'http://127.0.0.1:8000']);
    Queue::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Subscribes a verified customer account to the given alert types and
 * returns the subscribe result (contact id + subscription rows).
 *
 * @param  list<string>  $types
 * @return array{status: string, contact_id: string, subscriptions: list<array{id: string, alert_type: string, status: string}>, verification_token: ?string}
 */
function alertSubscribe(string $email, string $variantId, array $types): array
{
    $customer = watchCustomer($email);

    return app(SubscribeToWatch::class)->subscribe([
        'email' => $email,
        'customer_id' => $customer->id,
        'variant_id' => $variantId,
        'types' => $types,
    ]);
}

function alertSetStock(array $catalog, int $onHand, ?string $operationKey = null): string
{
    $operationKey ??= (string) Str::uuid();
    app(AdjustStock::class)->adjust($catalog['owner'], [
        'variant_id' => $catalog['physical_variant'],
        'operation_key' => $operationKey,
        'tracks_inventory' => true,
        'on_hand' => $onHand,
        'low_stock_threshold' => 1,
    ]);

    return $operationKey;
}

function alertDeliver(string $intentId): void
{
    (new SendWatchAlert($intentId))->handle(app(DeliverWatchAlert::class));
}

it('ShoppingToolsAlert creates one deduplicated intent per eligible stock subscription on a restock transition and dispatches its send job', function (): void {
    $catalog = wishlistCatalog();
    $first = alertSubscribe('restock-one@example.test', $catalog['physical_variant'], ['stock']);
    $second = alertSubscribe('restock-two@example.test', $catalog['physical_variant'], ['stock']);
    expect($first['status'])->toBe('active')->and($second['status'])->toBe('active');

    // Draining to zero is not an availability-entering change.
    alertSetStock($catalog, 0);
    expect(DB::table('watch_alert_intents')->count())->toBe(0);
    Queue::assertNotPushed(SendWatchAlert::class);

    $operationKey = alertSetStock($catalog, 3);
    $intents = DB::table('watch_alert_intents')->orderBy('recipient')->get();
    expect($intents)->toHaveCount(2)
        ->and($intents[0]->template)->toBe('watch-alert')
        ->and($intents[0]->kind)->toBe('event')
        ->and($intents[0]->status)->toBe('queued')
        ->and($intents[0]->alert_type)->toBe('stock')
        ->and($intents[0]->recipient)->toBe('restock-one@example.test')
        ->and($intents[0]->event_key)->toBe('adjust:'.$operationKey)
        ->and($intents[0]->subscription_id)->toBe($first['subscriptions'][0]['id'])
        ->and($intents[1]->recipient)->toBe('restock-two@example.test');
    Queue::assertPushed(SendWatchAlert::class, 2);

    // Replaying the same merchant operation changes nothing twice.
    alertSetStock($catalog, 3, $operationKey);
    expect(DB::table('watch_alert_intents')->count())->toBe(2);

    // A duplicate delivery of the same catalog event dedupes by key.
    app(EvaluateWatchEvent::class)->stock($catalog['physical_variant'], 'adjust:'.$operationKey);
    expect(DB::table('watch_alert_intents')->count())->toBe(2);
    Queue::assertPushed(SendWatchAlert::class, 2);
});

it('ShoppingToolsAlert creates no intents for pending, revoked or suppressed subscriptions', function (): void {
    $catalog = wishlistCatalog();
    $pending = app(SubscribeToWatch::class)->subscribe([
        'email' => 'alert-pending@example.test',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['stock'],
    ]);
    expect($pending['status'])->toBe('verification_required');
    $revoked = alertSubscribe('alert-revoked@example.test', $catalog['physical_variant'], ['stock']);
    app(UpdateDeliveryPreference::class)->revoke($revoked['contact_id'], 'stock_alert', 'unsubscribe_link');
    $suppressed = alertSubscribe('alert-suppressed@example.test', $catalog['physical_variant'], ['stock']);
    app(UpdateDeliveryPreference::class)->suppress($suppressed['contact_id'], 'global', 'complaint', 'provider_feedback');

    alertSetStock($catalog, 0);
    alertSetStock($catalog, 4);
    expect(DB::table('watch_alert_intents')->count())->toBe(0);
    Queue::assertNotPushed(SendWatchAlert::class);
});

it('ShoppingToolsAlert never alerts on reservation holds, commits or releases, but a restock under a full reservation alerts from the committed position', function (): void {
    $catalog = wishlistCatalog();
    alertSubscribe('reserved@example.test', $catalog['physical_variant'], ['stock']);
    alertSetStock($catalog, 2);
    $allocate = app(AllocateInventory::class);
    $intentId = (string) Str::uuid();
    $line = new InventoryLine($catalog['physical_variant'], 2);
    $allocate->hold(new InventoryHold($intentId, (string) Str::uuid(), new DateTimeImmutable('+30 minutes'), [$line]));
    $allocate->commit($intentId, (string) Str::uuid());
    $allocate->release(new InventoryMutation($intentId, (string) Str::uuid(), [$line]));
    expect(DB::table('watch_alert_intents')->count())->toBe(0);
    Queue::assertNotPushed(SendWatchAlert::class);

    // Hold everything again: the committed available position is zero.
    $secondIntent = (string) Str::uuid();
    $allocate->hold(new InventoryHold($secondIntent, (string) Str::uuid(), new DateTimeImmutable('+30 minutes'), [$line]));
    // A merchant restock raises on-hand; availability enters from the
    // committed position (4 on hand - 2 reserved = 2), so it alerts.
    alertSetStock($catalog, 4);
    expect(DB::table('watch_alert_intents')->count())->toBe(1);
    // Releasing the reservation afterwards is still silent.
    $allocate->release(new InventoryMutation($secondIntent, (string) Str::uuid(), [$line]));
    expect(DB::table('watch_alert_intents')->count())->toBe(1);
});

it('ShoppingToolsAlert alerts on the returns restock act, which returns units to on-hand', function (): void {
    $catalog = wishlistCatalog();
    alertSubscribe('returns@example.test', $catalog['physical_variant'], ['stock']);
    alertSetStock($catalog, 0);
    app(AllocateInventory::class)->restock(new InventoryMutation(
        (string) Str::uuid(),
        (string) Str::uuid(),
        [new InventoryLine($catalog['physical_variant'], 3)],
    ));
    $intents = DB::table('watch_alert_intents')->get();
    expect($intents)->toHaveCount(1)
        ->and($intents[0]->alert_type)->toBe('stock')
        ->and($intents[0]->status)->toBe('queued');
});

it('ShoppingToolsAlert alerts on a price drop against the activation baseline, never on a rise or an unchanged price', function (): void {
    $catalog = wishlistCatalog();
    $first = alertSubscribe('price-one@example.test', $catalog['physical_variant'], ['price']);
    expect(DB::table('watch_subscriptions')->where('id', $first['subscriptions'][0]['id'])->value('baseline_amount'))->toBe(2499);

    wishlistProductUpdate($catalog, $catalog['physical_id'], ['price_amount' => 2000]);
    $intents = DB::table('watch_alert_intents')->get();
    expect($intents)->toHaveCount(1)
        ->and($intents[0]->alert_type)->toBe('price')
        ->and($intents[0]->recipient)->toBe('price-one@example.test');

    // A second watcher activates at the dropped price; its baseline is
    // 2000, so the same price is not a drop for them.
    alertSubscribe('price-two@example.test', $catalog['physical_variant'], ['price']);
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['price_amount' => 2600]);
    expect(DB::table('watch_alert_intents')->count())->toBe(1);
    // A save that leaves the price unchanged alerts nobody either.
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['title' => 'Studio Lamp Deluxe']);
    expect(DB::table('watch_alert_intents')->count())->toBe(1);
});

it('ShoppingToolsAlert alerts on price drops saved through the variants matrix as well', function (): void {
    $catalog = ['owner' => catalogInstalledOwner()];
    $tracked = catalogTrackedVariant($catalog['owner'], 5, null);
    alertSubscribe('matrix@example.test', $tracked['variant_id'], ['price']);
    $image = (string) DB::table('product_media')->where('product_id', $tracked['product_id'])->where('sort_order', 0)->value('media_asset_id');
    $version = (int) DB::table('products')->where('id', $tracked['product_id'])->value('version');
    $matrix = [
        'id' => $tracked['product_id'],
        'version' => $version,
        'options' => [],
        'variants' => [[
            'id' => $tracked['variant_id'], 'sku' => 'LAMP-001', 'price_amount' => 1999,
            'is_default' => true, 'available' => true, 'options' => [],
            'image_asset_id' => $image, 'image_alt' => 'Brushed steel studio lamp',
            'weight_grams' => 1200, 'length_mm' => 380, 'width_mm' => 180, 'height_mm' => 120,
        ]],
    ];
    app(SaveVariants::class)->save($catalog['owner'], $matrix);
    $intents = DB::table('watch_alert_intents')->get();
    expect($intents)->toHaveCount(1)
        ->and($intents[0]->alert_type)->toBe('price')
        ->and($intents[0]->recipient)->toBe('matrix@example.test');
});

it('ShoppingToolsAlert treats a currency or exponent change as no drop, however large the nominal gap', function (): void {
    $catalog = wishlistCatalog();
    $watcher = alertSubscribe('currency@example.test', $catalog['physical_variant'], ['price']);
    $subscriptionId = $watcher['subscriptions'][0]['id'];
    DB::table('watch_subscriptions')->where('id', $subscriptionId)->update([
        'baseline_amount' => 999999, 'baseline_currency' => 'EUR', 'baseline_exponent' => 2,
    ]);
    app(EvaluateWatchEvent::class)->price($catalog['physical_variant'], 'product:currency-check:1');
    expect(DB::table('watch_alert_intents')->count())->toBe(0);
    DB::table('watch_subscriptions')->where('id', $subscriptionId)->update([
        'baseline_amount' => 25, 'baseline_currency' => 'USD', 'baseline_exponent' => 0,
    ]);
    app(EvaluateWatchEvent::class)->price($catalog['physical_variant'], 'product:currency-check:2');
    expect(DB::table('watch_alert_intents')->count())->toBe(0);
});

it('ShoppingToolsAlert caps alerts at one of each type per variant and recipient per 24 hours', function (): void {
    $catalog = wishlistCatalog();
    alertSubscribe('capped@example.test', $catalog['physical_variant'], ['stock', 'price']);
    alertSetStock($catalog, 0);
    alertSetStock($catalog, 3);
    expect(DB::table('watch_alert_intents')->where('alert_type', 'stock')->count())->toBe(1);
    // Flapping inside the window: drain and restock again — capped.
    alertSetStock($catalog, 0);
    alertSetStock($catalog, 6);
    expect(DB::table('watch_alert_intents')->where('alert_type', 'stock')->count())->toBe(1);
    // The price cap is independent of the stock cap.
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['price_amount' => 2000]);
    expect(DB::table('watch_alert_intents')->where('alert_type', 'price')->count())->toBe(1);
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['price_amount' => 2499]);
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['price_amount' => 1900]);
    expect(DB::table('watch_alert_intents')->where('alert_type', 'price')->count())->toBe(1);
    // After the window passes, a fresh transition alerts again.
    Carbon::setTestNow(Carbon::parse('2026-10-17T19:00:01Z')->utc());
    alertSetStock($catalog, 0);
    alertSetStock($catalog, 2);
    expect(DB::table('watch_alert_intents')->where('alert_type', 'stock')->count())->toBe(2);
});

it('ShoppingToolsAlert delivers the alert rendered from current state at send time and marks the intent sent', function (): void {
    $catalog = wishlistCatalog();
    $watcher = alertSubscribe('deliver@example.test', $catalog['physical_variant'], ['price']);
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['price_amount' => 2000]);
    $intent = DB::table('watch_alert_intents')->first();
    expect($intent)->not->toBeNull()->and($intent->status)->toBe('queued');
    // The price drops further before the worker runs: the mail must
    // render the current 1800, not the 2000 that triggered the alert.
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['price_amount' => 1800]);
    expect(DB::table('watch_alert_intents')->count())->toBe(1);

    alertDeliver((string) $intent->id);
    $sent = DB::table('watch_alert_intents')->where('id', $intent->id)->first();
    expect($sent->status)->toBe('sent')->and($sent->sent_at)->not->toBeNull();
    $messages = Mail::mailer()->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    $email = $messages->first()->getOriginalMessage();
    expect($email->getTo()[0]->getAddress())->toBe('deliver@example.test')
        ->and($email->getSubject())->toBe('Price drop: Studio Lamp')
        ->and($email->getHtmlBody())->toContain('Studio Lamp')
        ->and($email->getHtmlBody())->toContain('USD 18.00')
        ->and($email->getHtmlBody())->toContain('/products/studio-lamp')
        ->and($email->getFrom()[0]->getAddress())->toBe('store@example.test');
    preg_match('/token=([A-Za-z0-9._-]+)/', (string) $email->getHtmlBody(), $matches);
    expect(app(UnsubscribeTokens::class)->parse($matches[1] ?? ''))
        ->toBe(['contact_id' => $watcher['contact_id'], 'purpose' => 'price_alert']);
    // A delivered intent is final: a second delivery run changes nothing.
    alertDeliver((string) $intent->id);
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
    expect(DB::table('watch_alert_intents')->where('id', $intent->id)->value('status'))->toBe('sent');
});

it('ShoppingToolsAlert cancels the send when the contact unsubscribes after the event', function (): void {
    $catalog = wishlistCatalog();
    $watcher = alertSubscribe('unsub@example.test', $catalog['physical_variant'], ['stock']);
    alertSetStock($catalog, 0);
    alertSetStock($catalog, 3);
    $intent = DB::table('watch_alert_intents')->first();
    app(UpdateDeliveryPreference::class)->revoke($watcher['contact_id'], 'stock_alert', 'unsubscribe_link');
    alertDeliver((string) $intent->id);
    $row = DB::table('watch_alert_intents')->where('id', $intent->id)->first();
    expect($row->status)->toBe('cancelled')->and($row->error_code)->toBe('delivery_not_allowed');
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('ShoppingToolsAlert cancels the send when a suppression lands after the event', function (): void {
    $catalog = wishlistCatalog();
    $watcher = alertSubscribe('suppress-send@example.test', $catalog['physical_variant'], ['stock']);
    alertSetStock($catalog, 0);
    alertSetStock($catalog, 3);
    $intent = DB::table('watch_alert_intents')->first();
    app(UpdateDeliveryPreference::class)->suppress($watcher['contact_id'], 'global', 'complaint', 'provider_feedback');
    alertDeliver((string) $intent->id);
    $row = DB::table('watch_alert_intents')->where('id', $intent->id)->first();
    expect($row->status)->toBe('cancelled')->and($row->error_code)->toBe('delivery_not_allowed');
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('ShoppingToolsAlert cancels the send when the variant leaves publication after the event', function (): void {
    $catalog = wishlistCatalog();
    alertSubscribe('unpublish@example.test', $catalog['physical_variant'], ['stock']);
    alertSetStock($catalog, 0);
    alertSetStock($catalog, 3);
    $intent = DB::table('watch_alert_intents')->first();
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['status' => 'draft']);
    alertDeliver((string) $intent->id);
    $row = DB::table('watch_alert_intents')->where('id', $intent->id)->first();
    expect($row->status)->toBe('cancelled')->and($row->error_code)->toBe('no_longer_listed');
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('ShoppingToolsAlert cancels the send when the triggering condition reverses before it', function (): void {
    $catalog = wishlistCatalog();
    alertSubscribe('reversal@example.test', $catalog['physical_variant'], ['stock', 'price']);
    alertSetStock($catalog, 0);
    alertSetStock($catalog, 3);
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['price_amount' => 2000]);
    $stockIntent = DB::table('watch_alert_intents')->where('alert_type', 'stock')->first();
    $priceIntent = DB::table('watch_alert_intents')->where('alert_type', 'price')->first();
    // Both conditions reverse before the worker runs.
    alertSetStock($catalog, 0);
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['price_amount' => 2499]);
    alertDeliver((string) $stockIntent->id);
    alertDeliver((string) $priceIntent->id);
    expect(DB::table('watch_alert_intents')->where('id', $stockIntent->id)->value('error_code'))->toBe('condition_no_longer_met')
        ->and(DB::table('watch_alert_intents')->where('id', $priceIntent->id)->value('error_code'))->toBe('condition_no_longer_met')
        ->and(DB::table('watch_alert_intents')->where('status', 'cancelled')->count())->toBe(2);
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('ShoppingToolsAlert cancels the send when the subscription itself is cancelled after the event', function (): void {
    $catalog = wishlistCatalog();
    $watcher = alertSubscribe('cancel-sub@example.test', $catalog['physical_variant'], ['stock']);
    alertSetStock($catalog, 0);
    alertSetStock($catalog, 3);
    $intent = DB::table('watch_alert_intents')->first();
    app(SubscribeToWatch::class)->cancel($watcher['contact_id'], $watcher['subscriptions'][0]['id']);
    alertDeliver((string) $intent->id);
    $row = DB::table('watch_alert_intents')->where('id', $intent->id)->first();
    expect($row->status)->toBe('cancelled')->and($row->error_code)->toBe('subscription_inactive');
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('ShoppingToolsAlert alerts stock watchers when a product re-enters publication in stock, without a spurious price alert', function (): void {
    $catalog = wishlistCatalog();
    alertSubscribe('republish@example.test', $catalog['physical_variant'], ['stock', 'price']);
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['status' => 'draft']);
    expect(DB::table('watch_alert_intents')->count())->toBe(0);
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['status' => 'published']);
    $intents = DB::table('watch_alert_intents')->get();
    expect($intents)->toHaveCount(1)
        ->and($intents[0]->alert_type)->toBe('stock');
});
