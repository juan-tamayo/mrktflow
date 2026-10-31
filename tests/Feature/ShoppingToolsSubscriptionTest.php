<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\ArchiveProduct;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Platform\Actions\UpdateDeliveryPreference;
use App\Domain\Platform\Support\DeliveryPolicy;
use App\Domain\Platform\Support\UnsubscribeTokens;
use App\Domain\ShoppingTools\Actions\ReadWatchSubscriptions;
use App\Domain\ShoppingTools\Actions\SaveWishlistItem;
use App\Domain\ShoppingTools\Actions\SubscribeToWatch;
use App\Domain\ShoppingTools\Actions\VerifyWatchEmail;
use App\Domain\ShoppingTools\Support\WishlistOwner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-16T18:00:00Z')->utc());
    config(['app.url' => 'http://127.0.0.1:8000']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function watchCustomer(string $email, bool $verified = true): CustomerAccount
{
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Watcher', 'email' => $email,
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true, 'session_version' => 1,
        'email_verified_at' => $verified ? now() : null,
    ])->save();

    return $customer;
}

it('ShoppingToolsSubscription subscribing while unverified creates pending watches, grants nothing and queues one verification intent', function (): void {
    $catalog = wishlistCatalog();
    $result = app(SubscribeToWatch::class)->subscribe([
        'email' => '  Watcher@Example.TEST ',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['stock', 'price'],
    ]);
    expect($result['status'])->toBe('verification_required')
        ->and($result['verification_token'])->toBeString()
        ->and(preg_match('/^[a-f0-9]{64}$/D', (string) $result['verification_token']))->toBe(1);
    $contact = DB::table('delivery_contacts')->where('email', 'watcher@example.test')->first();
    expect($contact)->not->toBeNull()
        ->and($contact->email_verified_at)->toBeNull();
    $rows = DB::table('watch_subscriptions')->where('contact_id', $contact->id)->orderBy('alert_type')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('status')->unique()->all())->toBe(['pending'])
        ->and($rows->pluck('alert_type')->all())->toBe(['price', 'stock']);
    expect(DB::table('purpose_grants')->where('contact_id', $contact->id)->count())->toBe(0);
    expect(app(DeliveryPolicy::class)->marketingAllowed('watcher@example.test', 'stock_alert'))->toBeFalse();
    $challenge = DB::table('watch_verifications')->where('contact_id', $contact->id)->first();
    expect($challenge)->not->toBeNull()
        ->and($challenge->token_digest)->toBe(hash('sha256', (string) $result['verification_token']))
        ->and($challenge->email_digest)->toBe(hash('sha256', 'watcher@example.test'))
        ->and($challenge->consumed_at)->toBeNull();
    expect(json_encode(DB::table('watch_verifications')->get(), JSON_THROW_ON_ERROR))
        ->not->toContain((string) $result['verification_token']);
    $intent = DB::table('watch_verification_intents')->where('contact_id', $contact->id)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->template)->toBe('watch-verification')
        ->and($intent->recipient)->toBe('watcher@example.test')
        ->and($intent->status)->toBe('queued')
        ->and($intent->verification_id)->toBe($challenge->id);

    // Re-subscribing while still unverified keeps one row per type and
    // rotates the challenge: the first token dies, a fresh one is issued.
    $again = app(SubscribeToWatch::class)->subscribe([
        'email' => 'watcher@example.test',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['stock', 'price'],
    ]);
    expect($again['verification_token'])->not->toBe($result['verification_token']);
    expect(DB::table('watch_subscriptions')->where('contact_id', $contact->id)->count())->toBe(2);
    expect(DB::table('watch_verifications')->where('contact_id', $contact->id)->whereNotNull('revoked_at')->count())->toBe(1);
    expect(fn () => app(VerifyWatchEmail::class)->consume((string) $result['verification_token']))
        ->toThrow(NotFoundHttpException::class);
    expect(DB::table('delivery_contacts')->where('id', $contact->id)->value('email_verified_at'))->toBeNull();
});

it('ShoppingToolsSubscription confirming verification marks the contact verified, grants as double opt-in, activates watches and snapshots the price baseline; replay changes nothing', function (): void {
    $catalog = wishlistCatalog();
    $subscribe = app(SubscribeToWatch::class)->subscribe([
        'email' => 'confirm@example.test',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['stock', 'price'],
    ]);
    $contactId = (string) $subscribe['contact_id'];
    $variant = DB::table('product_variants')->where('id', $catalog['physical_variant'])->first();

    $consumed = app(VerifyWatchEmail::class)->consume((string) $subscribe['verification_token']);
    expect($consumed)->toBe($contactId);
    $prefs = app(UpdateDeliveryPreference::class);
    $state = $prefs->state($contactId);
    expect($state['verified'])->toBeTrue()
        ->and($state['purposes']['stock_alert'])->toBeTrue()
        ->and($state['purposes']['price_alert'])->toBeTrue()
        ->and($state['purposes']['newsletter'])->toBeFalse();
    $grants = DB::table('purpose_grants')->where('contact_id', $contactId)->get()->keyBy('purpose');
    expect($grants['stock_alert']->source)->toBe('double_opt_in')
        ->and($grants['price_alert']->source)->toBe('double_opt_in')
        ->and($grants['stock_alert']->notice_revision)->toBe(UpdateDeliveryPreference::NOTICE_REVISION);
    $rows = DB::table('watch_subscriptions')->where('contact_id', $contactId)->get()->keyBy('alert_type');
    expect($rows['stock']->status)->toBe('active')
        ->and($rows['stock']->baseline_amount)->toBeNull()
        ->and($rows['price']->status)->toBe('active')
        ->and($rows['price']->baseline_amount)->toBe($variant->price_amount)
        ->and($rows['price']->baseline_currency)->toBe($variant->currency)
        ->and($rows['price']->baseline_exponent)->toBe($variant->exponent);
    $challenge = DB::table('watch_verifications')->where('contact_id', $contactId)->first();
    expect($challenge->consumed_at)->not->toBeNull()
        ->and($challenge->token_digest)->toBeNull();
    $audits = DB::table('outbox_events')->where('event_type', 'platform.audit.recorded')->count();
    $grantedAt = $grants['stock_alert']->granted_at;

    // Replay: the spent token is dead and the replay changes no fact.
    expect(fn () => app(VerifyWatchEmail::class)->consume((string) $subscribe['verification_token']))
        ->toThrow(NotFoundHttpException::class);
    expect(DB::table('purpose_grants')->where('contact_id', $contactId)->where('purpose', 'stock_alert')->value('granted_at'))
        ->toBe($grantedAt);
    expect(DB::table('outbox_events')->where('event_type', 'platform.audit.recorded')->count())->toBe($audits);
    expect(DB::table('watch_subscriptions')->where('contact_id', $contactId)->where('status', 'active')->count())->toBe(2);
});

it('ShoppingToolsSubscription stock and price opt-ins are separate: a stock-only subscription never grants price alerts', function (): void {
    $catalog = wishlistCatalog();
    $subscribe = app(SubscribeToWatch::class)->subscribe([
        'email' => 'stockonly@example.test',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['stock'],
    ]);
    app(VerifyWatchEmail::class)->consume((string) $subscribe['verification_token']);
    $policy = app(DeliveryPolicy::class);
    expect($policy->marketingAllowed('stockonly@example.test', 'stock_alert'))->toBeTrue()
        ->and($policy->marketingAllowed('stockonly@example.test', 'price_alert'))->toBeFalse();
    expect(DB::table('watch_subscriptions')->where('contact_id', $subscribe['contact_id'])->count())->toBe(1)
        ->and(DB::table('purpose_grants')->where('contact_id', $subscribe['contact_id'])->count())->toBe(1);
    $read = app(ReadWatchSubscriptions::class)->forContact((string) $subscribe['contact_id']);
    expect($read)->toHaveCount(1)
        ->and($read[0]['alert_type'])->toBe('stock')
        ->and($read[0]['alertable'])->toBeTrue()
        ->and($read[0]['title'])->toBe('Studio Lamp');
});

it('ShoppingToolsSubscription an already-verified contact subscribes active immediately, with no verification mail and idempotent replays', function (): void {
    $catalog = wishlistCatalog();
    $prefs = app(UpdateDeliveryPreference::class);
    $contactId = $prefs->ensureContact('known@example.test');
    $prefs->markVerified($contactId, 'double_opt_in');
    $result = app(SubscribeToWatch::class)->subscribe([
        'email' => 'known@example.test',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['price'],
    ]);
    expect($result['status'])->toBe('active')
        ->and($result['verification_token'])->toBeNull();
    expect(DB::table('watch_verifications')->count())->toBe(0)
        ->and(DB::table('watch_verification_intents')->count())->toBe(0);
    $grant = DB::table('purpose_grants')->where('contact_id', $contactId)->where('purpose', 'price_alert')->first();
    expect($grant->source)->toBe('watch_options')
        ->and($grant->revoked_at)->toBeNull();
    $again = app(SubscribeToWatch::class)->subscribe([
        'email' => 'known@example.test',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['price'],
    ]);
    expect($again['status'])->toBe('active');
    expect(DB::table('watch_subscriptions')->where('contact_id', $contactId)->count())->toBe(1)
        ->and(DB::table('purpose_grants')->where('contact_id', $contactId)->count())->toBe(1);
});

it('ShoppingToolsSubscription a signed-in customer with a verified account email subscribes active and links the contact', function (): void {
    $catalog = wishlistCatalog();
    $customer = watchCustomer('member@example.test');
    $result = app(SubscribeToWatch::class)->subscribe([
        'email' => 'member@example.test',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['stock'],
        'customer_id' => $customer->id,
    ]);
    expect($result['status'])->toBe('active')
        ->and($result['verification_token'])->toBeNull();
    $contact = DB::table('delivery_contacts')->where('email', 'member@example.test')->first();
    expect($contact->customer_id)->toBe($customer->id)
        ->and($contact->email_verified_at)->not->toBeNull();
    expect(DB::table('watch_verifications')->count())->toBe(0);
});

it('ShoppingToolsSubscription verification tokens fail closed: tampered, unknown and expired tokens change nothing', function (): void {
    $catalog = wishlistCatalog();
    $subscribe = app(SubscribeToWatch::class)->subscribe([
        'email' => 'fragile@example.test',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['stock'],
    ]);
    $token = (string) $subscribe['verification_token'];
    $tampered = ($token[0] === 'a' ? 'b' : 'a').substr($token, 1);
    $verify = app(VerifyWatchEmail::class);
    expect(fn () => $verify->consume($tampered))->toThrow(NotFoundHttpException::class);
    expect(fn () => $verify->consume(str_repeat('0', 64)))->toThrow(NotFoundHttpException::class);
    expect(fn () => $verify->consume('not-a-token'))->toThrow(NotFoundHttpException::class);
    Carbon::setTestNow(Carbon::parse('2026-10-17T18:00:01Z')->utc());
    expect(fn () => $verify->consume($token))->toThrow(NotFoundHttpException::class);
    $contact = DB::table('delivery_contacts')->where('email', 'fragile@example.test')->first();
    expect($contact->email_verified_at)->toBeNull();
    expect(DB::table('watch_verifications')->where('contact_id', $contact->id)->value('consumed_at'))->toBeNull()
        ->and(DB::table('watch_subscriptions')->where('contact_id', $contact->id)->value('status'))->toBe('pending')
        ->and(DB::table('purpose_grants')->where('contact_id', $contact->id)->count())->toBe(0);
});

it('ShoppingToolsSubscription suppression wins: a complaint-suppressed contact is never alertable, and double opt-in cannot clear a complaint', function (): void {
    $catalog = wishlistCatalog();
    $prefs = app(UpdateDeliveryPreference::class);
    $policy = app(DeliveryPolicy::class);

    // Active watch first, complaint afterwards: policy blocks, read model agrees.
    $contactId = $prefs->ensureContact('angry@example.test');
    $prefs->markVerified($contactId, 'double_opt_in');
    app(SubscribeToWatch::class)->subscribe([
        'email' => 'angry@example.test',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['stock'],
    ]);
    expect($policy->marketingAllowed('angry@example.test', 'stock_alert'))->toBeTrue();
    $prefs->recordProviderFeedback('angry@example.test', 'complaint');
    expect($policy->marketingAllowed('angry@example.test', 'stock_alert'))->toBeFalse();
    $read = app(ReadWatchSubscriptions::class)->forContact($contactId);
    expect($read)->toHaveCount(1)
        ->and($read[0]['status'])->toBe('active')
        ->and($read[0]['alertable'])->toBeFalse();

    // Complaint first, then subscribe + verify: the grant is recorded by
    // the double opt-in but the complaint suppression survives it.
    $prefs->recordProviderFeedback('bounced@example.test', 'hard_bounce');
    $pending = app(SubscribeToWatch::class)->subscribe([
        'email' => 'bounced@example.test',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['price'],
    ]);
    expect($pending['status'])->toBe('verification_required');
    app(VerifyWatchEmail::class)->consume((string) $pending['verification_token']);
    $state = $prefs->state((string) $pending['contact_id']);
    expect($state['verified'])->toBeTrue()
        ->and($state['purposes']['price_alert'])->toBeTrue()
        ->and($state['suppressions'])->toHaveCount(1)
        ->and($state['suppressions'][0]['reason'])->toBe('hard_bounce');
    expect($policy->marketingAllowed('bounced@example.test', 'price_alert'))->toBeFalse();
    $readBounced = app(ReadWatchSubscriptions::class)->forContact((string) $pending['contact_id']);
    expect($readBounced[0]['alertable'])->toBeFalse();
});

it('ShoppingToolsSubscription saving a wishlist item alone sends nothing and creates no watch state', function (): void {
    $catalog = wishlistCatalog();
    $guest = WishlistOwner::guest('watch-bystander-token');
    app(SaveWishlistItem::class)->save($guest, ['variant_id' => $catalog['physical_variant']]);
    expect(DB::table('delivery_contacts')->count())->toBe(0)
        ->and(DB::table('purpose_grants')->count())->toBe(0)
        ->and(DB::table('watch_subscriptions')->count())->toBe(0)
        ->and(DB::table('watch_verifications')->count())->toBe(0)
        ->and(DB::table('watch_verification_intents')->count())->toBe(0);
});

it('ShoppingToolsSubscription subscribing to unpublished or unknown variants fails generically and records nothing', function (): void {
    $catalog = wishlistCatalog();
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['status' => 'draft']);
    $subscribe = app(SubscribeToWatch::class);
    expect(fn () => $subscribe->subscribe([
        'email' => 'probe@example.test',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['stock'],
    ]))->toThrow(ValidationException::class);
    expect(fn () => $subscribe->subscribe([
        'email' => 'probe@example.test',
        'variant_id' => (string) Str::uuid(),
        'types' => ['stock'],
    ]))->toThrow(ValidationException::class);
    expect(DB::table('delivery_contacts')->count())->toBe(0)
        ->and(DB::table('watch_subscriptions')->count())->toBe(0)
        ->and(DB::table('watch_verification_intents')->count())->toBe(0);
});

it('ShoppingToolsSubscription an out-of-stock but listed variant accepts a stock watch, and the read model degrades privately when the product is unpublished', function (): void {
    $catalog = wishlistCatalog();
    app(AdjustStock::class)->adjust($catalog['owner'], [
        'variant_id' => $catalog['physical_variant'],
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 0,
        'low_stock_threshold' => 1,
    ]);
    $prefs = app(UpdateDeliveryPreference::class);
    $contactId = $prefs->ensureContact('patient@example.test');
    $prefs->markVerified($contactId, 'double_opt_in');
    $result = app(SubscribeToWatch::class)->subscribe([
        'email' => 'patient@example.test',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['stock'],
    ]);
    expect($result['status'])->toBe('active');
    $read = app(ReadWatchSubscriptions::class)->forContact($contactId);
    expect($read[0]['state'])->toBe('listed')
        ->and($read[0]['availability'])->toBe('out_of_stock')
        ->and($read[0]['alertable'])->toBeTrue();

    $version = (int) DB::table('products')->where('id', $catalog['physical_id'])->value('version');
    app(ArchiveProduct::class)->archive($catalog['owner'], ['id' => $catalog['physical_id'], 'version' => $version]);
    $degraded = app(ReadWatchSubscriptions::class)->forContact($contactId);
    expect($degraded)->toHaveCount(1)
        ->and($degraded[0]['state'])->toBe('removed')
        ->and($degraded[0]['alertable'])->toBeTrue();
    expect(json_encode($degraded, JSON_THROW_ON_ERROR))->not->toContain('Studio Lamp', 'LAMP-001', 'studio-lamp', '2499');
});

it('ShoppingToolsSubscription cancelling stops one variant watch only, keeps the purpose grant, and resubscribing reactivates the same row', function (): void {
    $catalog = wishlistCatalog();
    $prefs = app(UpdateDeliveryPreference::class);
    $contactId = $prefs->ensureContact('chooser@example.test');
    $prefs->markVerified($contactId, 'double_opt_in');
    $watch = app(SubscribeToWatch::class);
    $watch->subscribe(['email' => 'chooser@example.test', 'variant_id' => $catalog['physical_variant'], 'types' => ['stock', 'price']]);
    $watch->subscribe(['email' => 'chooser@example.test', 'variant_id' => $catalog['digital_variant'], 'types' => ['stock']]);
    $rows = app(ReadWatchSubscriptions::class)->forContact($contactId);
    $lampStock = collect($rows)->firstWhere(fn (array $row): bool => $row['variant_id'] === $catalog['physical_variant'] && $row['alert_type'] === 'stock');
    expect($lampStock)->not->toBeNull();
    expect($watch->cancel($contactId, (string) $lampStock['id']))->toBeTrue();
    expect($watch->cancel($contactId, (string) $lampStock['id']))->toBeFalse();
    expect($watch->cancel($contactId, (string) Str::uuid()))->toBeFalse();
    $after = app(ReadWatchSubscriptions::class)->forContact($contactId);
    $byKey = collect($after)->keyBy(fn (array $row): string => $row['variant_id'].':'.$row['alert_type']);
    expect($byKey[$catalog['physical_variant'].':stock']['status'])->toBe('cancelled')
        ->and($byKey[$catalog['physical_variant'].':stock']['alertable'])->toBeFalse()
        ->and($byKey[$catalog['physical_variant'].':price']['alertable'])->toBeTrue()
        ->and($byKey[$catalog['digital_variant'].':stock']['alertable'])->toBeTrue();
    expect(app(DeliveryPolicy::class)->marketingAllowed('chooser@example.test', 'stock_alert'))->toBeTrue();
    $watch->subscribe(['email' => 'chooser@example.test', 'variant_id' => $catalog['physical_variant'], 'types' => ['stock']]);
    expect(DB::table('watch_subscriptions')->where('contact_id', $contactId)->count())->toBe(3)
        ->and(DB::table('watch_subscriptions')->where('contact_id', $contactId)->where('variant_id', $catalog['physical_variant'])->where('alert_type', 'stock')->value('status'))->toBe('active');
});

it('ShoppingToolsSubscription the verify landing GET changes nothing and the confirm POST verifies exactly once', function (): void {
    $catalog = wishlistCatalog();
    $subscribe = app(SubscribeToWatch::class)->subscribe([
        'email' => 'scanner@example.test',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['stock'],
    ]);
    $token = (string) $subscribe['verification_token'];
    $contactId = (string) $subscribe['contact_id'];
    $this->get('/watch/verify?token='.$token)->assertOk()->assertSee('Confirm');
    expect(DB::table('delivery_contacts')->where('id', $contactId)->value('email_verified_at'))->toBeNull()
        ->and(DB::table('watch_verifications')->where('contact_id', $contactId)->value('consumed_at'))->toBeNull()
        ->and(DB::table('watch_subscriptions')->where('contact_id', $contactId)->value('status'))->toBe('pending');
    $this->post('/watch/verify', ['token' => $token])->assertOk();
    expect(DB::table('delivery_contacts')->where('id', $contactId)->value('email_verified_at'))->not->toBeNull()
        ->and(DB::table('watch_subscriptions')->where('contact_id', $contactId)->value('status'))->toBe('active');
    $this->post('/watch/verify', ['token' => $token])->assertNotFound();
    $this->get('/watch/verify?token='.$token)->assertNotFound();
    $this->post('/watch/verify', ['token' => str_repeat('1', 64)])->assertNotFound();
});

it('ShoppingToolsSubscription the signed unsubscribe acts without any login and makes the watch non-alertable', function (): void {
    $catalog = wishlistCatalog();
    $prefs = app(UpdateDeliveryPreference::class);
    $contactId = $prefs->ensureContact('leaver@example.test');
    $prefs->markVerified($contactId, 'double_opt_in');
    app(SubscribeToWatch::class)->subscribe([
        'email' => 'leaver@example.test',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['stock'],
    ]);
    expect(app(ReadWatchSubscriptions::class)->forContact($contactId)[0]['alertable'])->toBeTrue();
    $token = app(UnsubscribeTokens::class)->issue($contactId, 'stock_alert');
    $this->post('/preferences/unsubscribe', ['token' => $token])->assertOk();
    expect(DB::table('purpose_grants')->where('contact_id', $contactId)->where('purpose', 'stock_alert')->value('revoked_at'))->not->toBeNull();
    $read = app(ReadWatchSubscriptions::class)->forContact($contactId);
    expect($read)->toHaveCount(1)
        ->and($read[0]['status'])->toBe('active')
        ->and($read[0]['alertable'])->toBeFalse();
});

it('ShoppingToolsSubscription a guest subscribing over HTTP gets a pending result, a queued intent and no token in the response', function (): void {
    $catalog = wishlistCatalog();
    $response = $this->postJson('/store-api/v1/watch/subscriptions', [
        'email' => 'httpguest@example.test',
        'variant_id' => $catalog['physical_variant'],
        'types' => ['stock', 'price'],
    ])->assertOk()->assertJsonPath('status', 'verification_required');
    $data = $response->json();
    expect($data)->not->toHaveKey('verification_token');
    expect(json_encode($data, JSON_THROW_ON_ERROR))->not->toMatch('/[a-f0-9]{64}/');
    $contact = DB::table('delivery_contacts')->where('email', 'httpguest@example.test')->first();
    expect($contact)->not->toBeNull();
    expect(DB::table('watch_subscriptions')->where('contact_id', $contact->id)->where('status', 'pending')->count())->toBe(2)
        ->and(DB::table('watch_verification_intents')->where('contact_id', $contact->id)->count())->toBe(1)
        ->and(DB::table('purpose_grants')->where('contact_id', $contact->id)->count())->toBe(0);
});

it('ShoppingToolsSubscription a signed-in verified customer subscribes over HTTP and cancels only their own watch', function (): void {
    $catalog = wishlistCatalog();
    $customer = watchCustomer('httpmember@example.test');
    $stranger = watchCustomer('httpstranger@example.test');
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $response = $this->postJson('/store-api/v1/watch/subscriptions', [
        'variant_id' => $catalog['physical_variant'],
        'types' => ['stock'],
    ])->assertOk()->assertJsonPath('status', 'active');
    $contact = DB::table('delivery_contacts')->where('email', 'httpmember@example.test')->first();
    expect($contact->customer_id)->toBe($customer->id);
    $subscription = DB::table('watch_subscriptions')->where('contact_id', $contact->id)->first();
    $index = $this->getJson('/store-api/v1/watch/subscriptions')->assertOk()->json('subscriptions');
    expect($index)->toHaveCount(1)
        ->and($index[0]['id'])->toBe($subscription->id)
        ->and($index[0]['alertable'])->toBeTrue();
    $this->actingAs($stranger, 'customer')->withSession(['customer_session_version' => 1]);
    $this->postJson('/store-api/v1/watch/subscriptions/'.$subscription->id.'/cancel')->assertNotFound();
    expect(DB::table('watch_subscriptions')->where('id', $subscription->id)->value('status'))->toBe('active');
    expect($this->getJson('/store-api/v1/watch/subscriptions')->json('subscriptions'))->toBe([]);
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->postJson('/store-api/v1/watch/subscriptions/'.$subscription->id.'/cancel')->assertOk();
    expect(DB::table('watch_subscriptions')->where('id', $subscription->id)->value('status'))->toBe('cancelled');
});
