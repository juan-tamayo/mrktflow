<?php

declare(strict_types=1);

use App\Domain\Platform\Support\DeliveryPolicy;
use App\Domain\ShoppingTools\Actions\SaveWishlistItem;
use App\Domain\ShoppingTools\Actions\SubscribeToWatch;
use App\Domain\ShoppingTools\Support\WishlistOwner;
use App\Jobs\SendWatchAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\CustomerRegistration;

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

/*
 * T060 integration gate (SHOP-01..SHOP-06). The slices beneath this
 * file each proved their own surface; these journeys prove the
 * surfaces compose in one continuous shopper flow, driving the real
 * HTTP controllers and merchant write paths in sequence rather than
 * re-asserting unit behavior.
 */

it('ShoppingToolsGate walks save to login merge to compare to cart as one continuous journey', function (): void {
    $catalog = compareCatalog();
    $customer = cartCustomer();
    $alpha = $catalog['cam_a1_variant'];
    $beta = $catalog['cam_a2_variant'];

    // The account already saved Beta on another device; the guest
    // below saves Alpha and Beta again, so the login merge must union
    // by variant and keep Beta exactly once.
    app(SaveWishlistItem::class)->save(WishlistOwner::customer($customer->id), ['variant_id' => $beta]);

    // Guest saves over HTTP, in this browser's anonymous session.
    $first = $this->postJson('/store-api/v1/wishlist/items', ['variant_id' => $alpha])->assertOk();
    expect($first->json('items'))->toHaveCount(1);
    $second = $this->postJson('/store-api/v1/wishlist/items', ['variant_id' => $beta])->assertOk();
    expect($second->json('items'))->toHaveCount(2);
    $guestList = $this->getJson('/store-api/v1/wishlist')->assertOk()->json('items');
    expect(collect($guestList)->pluck('variant_id')->sort()->values()->all())->toBe(collect([$alpha, $beta])->sort()->values()->all());

    // The compare surface the tray navigates to agrees with the saved
    // selections: same two variants, one column each, group-compatible.
    // (The tray cookie itself is client state, covered by the Vue unit
    // suites and the authored Chromium journeys; this gate asserts the
    // server read model the tray and table both render from.)
    $comparison = $this->getJson('/store-api/v1/compare?'.http_build_query(['items' => [$alpha, $beta]]))->assertOk();
    expect($comparison->json('compatible'))->toBeTrue()
        ->and(collect($comparison->json('selections'))->pluck('variant_id')->sort()->values()->all())->toBe(collect([$alpha, $beta])->sort()->values()->all())
        ->and($comparison->json('removed'))->toBe([]);
    // Login merges the guest list into the account list, once.
    $this->post('/account/login', [
        'email' => CustomerRegistration::input()['email'],
        'password' => CustomerRegistration::input()['password'],
    ])->assertRedirect('/account');
    $merged = $this->getJson('/store-api/v1/wishlist')->assertOk()->json('items');
    expect($merged)->toHaveCount(2)
        ->and(collect($merged)->pluck('variant_id')->sort()->values()->all())->toBe(collect([$alpha, $beta])->sort()->values()->all());
    expect(DB::table('wishlist_items')->count())->toBe(2)
        ->and(DB::table('wishlists')->whereNotNull('guest_token_hash')->count())->toBe(0);

    // The signed-in comparison page renders the same two columns and
    // supplies the account cart's revision for its add-to-cart forms.
    $comparePage = $this->get('/compare?'.http_build_query(['items' => [$alpha, $beta]]));
    $comparePage->assertOk()->assertSee('Alpha Camera')->assertSee('Beta Camera');
    $cartRevision = $comparePage->viewData('cartRevision');
    expect($cartRevision)->toBeInt();

    // Add both compared variants to the cart from the comparison
    // surface: the first through the HTML form the table renders
    // (variant + the cart revision the compare page supplied), the
    // second through the JSON surface against the advanced revision.
    $this->post('/cart/lines', [
        'variant_id' => $alpha,
        'quantity' => 1,
        'expected_revision' => $cartRevision,
    ])->assertRedirect('/cart');
    $cart = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    expect($cart['lines'])->toHaveCount(1)
        ->and($cart['lines'][0]['variantId'])->toBe($alpha);
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $beta,
        'quantity' => 2,
        'expectedRevision' => $cart['revision'],
    ])->assertOk();
    $cart = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    $lines = collect($cart['lines'])->keyBy('variantId');
    expect($lines->keys()->sort()->values()->all())->toBe(collect([$alpha, $beta])->sort()->values()->all())
        ->and($lines[$alpha]['quantity'])->toBe(1)
        ->and($lines[$beta]['quantity'])->toBe(2)
        ->and($cart['subtotal']['amount'])->toBe(2499 + (2 * 2599));

    // Buying from the comparison never consumed the saves.
    expect($this->getJson('/store-api/v1/wishlist')->assertOk()->json('items'))->toHaveCount(2);
});

it('ShoppingToolsGate walks subscribe unverified, verify, restock, one alert, and the product landing as one journey', function (): void {
    $catalog = wishlistCatalog();
    $lamp = $catalog['physical_variant'];
    alertSetStock($catalog, 0);

    // A guest subscribes over HTTP while the lamp is out of stock:
    // pending rows, a queued verification intent, no consent, and —
    // decisively — a restock before verification alerts nobody.
    $this->postJson('/store-api/v1/watch/subscriptions', [
        'email' => 'gatewatch@example.test',
        'variant_id' => $lamp,
        'types' => ['stock'],
    ])->assertOk()->assertJsonPath('status', 'verification_required');
    $contact = DB::table('delivery_contacts')->where('email', 'gatewatch@example.test')->first();
    expect($contact)->not->toBeNull()
        ->and(DB::table('watch_subscriptions')->where('contact_id', $contact->id)->value('status'))->toBe('pending')
        ->and(app(DeliveryPolicy::class)->marketingAllowed('gatewatch@example.test', 'stock_alert'))->toBeFalse();
    alertSetStock($catalog, 3);
    expect(DB::table('watch_alert_intents')->count())->toBe(0);
    Queue::assertNotPushed(SendWatchAlert::class);
    alertSetStock($catalog, 0);

    // The shopper clicks the emailed link. In production the raw token
    // travels only in that mail; here the domain action's re-subscribe
    // stands at the mailbox (it rotates the challenge and hands the
    // fresh token to the caller layer, the established test idiom).
    $mailed = app(SubscribeToWatch::class)->subscribe([
        'email' => 'gatewatch@example.test',
        'variant_id' => $lamp,
        'types' => ['stock'],
    ]);
    $token = (string) $mailed['verification_token'];
    expect(DB::table('watch_subscriptions')->where('contact_id', $contact->id)->count())->toBe(1);

    // The landing GET renders the confirmation and changes nothing;
    // the explicit POST is the double opt-in that activates the watch.
    $this->get('/watch/verify?token='.$token)->assertOk();
    expect(DB::table('watch_subscriptions')->where('contact_id', $contact->id)->value('status'))->toBe('pending');
    $this->post('/watch/verify', ['token' => $token])->assertOk();
    expect(DB::table('watch_subscriptions')->where('contact_id', $contact->id)->value('status'))->toBe('active')
        ->and(app(DeliveryPolicy::class)->marketingAllowed('gatewatch@example.test', 'stock_alert'))->toBeTrue();

    // A committed merchant restock fires exactly one alert intent and
    // its send job; replaying the same operation fires nothing more.
    $operationKey = alertSetStock($catalog, 4);
    $intents = DB::table('watch_alert_intents')->get();
    expect($intents)->toHaveCount(1)
        ->and($intents[0]->recipient)->toBe('gatewatch@example.test')
        ->and($intents[0]->alert_type)->toBe('stock')
        ->and($intents[0]->status)->toBe('queued');
    Queue::assertPushed(SendWatchAlert::class, 1);
    alertSetStock($catalog, 4, $operationKey);
    expect(DB::table('watch_alert_intents')->count())->toBe(1);

    // Delivery renders from current state, and following its product
    // link lands on the product in that same current, listed state.
    alertDeliver((string) $intents[0]->id);
    expect(DB::table('watch_alert_intents')->where('id', $intents[0]->id)->value('status'))->toBe('sent');
    $messages = Mail::mailer()->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    $email = $messages->first()->getOriginalMessage();
    expect($email->getTo()[0]->getAddress())->toBe('gatewatch@example.test')
        ->and($email->getSubject())->toBe('Back in stock: Studio Lamp')
        ->and($email->getHtmlBody())->toContain('/products/studio-lamp')
        ->and($email->getHtmlBody())->toContain('USD 24.99');
    $this->get('/products/studio-lamp')->assertOk()
        ->assertSee('Studio Lamp')
        ->assertSee('USD 24.99');
});
