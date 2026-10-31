<?php

declare(strict_types=1);

use App\Domain\ShoppingTools\Actions\ReadWishlist;
use App\Domain\ShoppingTools\Actions\SaveWishlistItem;
use App\Domain\ShoppingTools\Support\WishlistOwner;
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
 * T060 integration gate — session isolation (SHOP-01..SHOP-04). Two
 * customers and a guest interleave on the shared surfaces; no list,
 * tray-driven comparison, subscription or alert intent may cross
 * between them, and no session transition may expose the previous
 * principal's state.
 */

it('ShoppingToolsIsolation keeps two customers and a guest fully separate across interleaved sessions', function (): void {
    $catalog = wishlistCatalog();
    $lamp = $catalog['physical_variant'];
    $guide = $catalog['digital_variant'];
    $alice = cartCustomer();
    $bob = cartCustomer(['email' => 'bob@example.test', 'name' => 'Bob Shopper']);
    $save = app(SaveWishlistItem::class);

    // Each account holds one save made on another device.
    $save->save(WishlistOwner::customer($alice->id), ['variant_id' => $lamp]);
    $save->save(WishlistOwner::customer($bob->id), ['variant_id' => $guide]);

    // The guest in this browser saves the guide and sees only that.
    $this->post('/wishlist/items', ['variant_id' => $guide])->assertRedirect('/wishlist');
    $this->get('/wishlist')->assertOk()
        ->assertSee('Studio Lighting Guide')
        ->assertDontSee('Studio Lamp');

    // Alice signs in: the guest save unions into her account list,
    // Bob's save stays his alone, and nothing of Bob's appears.
    $this->post('/account/login', [
        'email' => CustomerRegistration::input()['email'],
        'password' => CustomerRegistration::input()['password'],
    ])->assertRedirect('/account');
    $this->get('/wishlist')->assertOk()
        ->assertSee('Studio Lamp')
        ->assertSee('Studio Lighting Guide');
    expect(DB::table('wishlist_items')->count())->toBe(3);

    // Alice's watch subscription belongs to her contact alone.
    $this->postJson('/store-api/v1/watch/subscriptions', [
        'variant_id' => $lamp,
        'types' => ['stock'],
    ])->assertOk();
    $aliceContact = DB::table('delivery_contacts')->where('email', 'shopper@example.test')->first();
    expect($aliceContact)->not->toBeNull();
    $aliceSubscription = DB::table('watch_subscriptions')->where('contact_id', $aliceContact->id)->first();
    expect($aliceSubscription)->not->toBeNull()
        ->and($this->getJson('/store-api/v1/watch/subscriptions')->assertOk()->json('subscriptions'))->toHaveCount(1);

    // Bob signs in (Alice signs out first): his list is the guide
    // alone, his watch index is empty, and Alice's subscription is
    // not his to cancel.
    $this->post('/account/logout')->assertRedirect('/account/login');
    $this->post('/account/login', [
        'email' => 'bob@example.test',
        'password' => CustomerRegistration::input()['password'],
    ])->assertRedirect('/account');
    $this->get('/wishlist')->assertOk()
        ->assertSee('Studio Lighting Guide')
        ->assertDontSee('Studio Lamp');
    expect($this->getJson('/store-api/v1/watch/subscriptions')->assertOk()->json('subscriptions'))->toBe([]);
    $this->postJson('/store-api/v1/watch/subscriptions/'.$aliceSubscription->id.'/cancel')->assertNotFound();
    expect(DB::table('watch_subscriptions')->where('id', $aliceSubscription->id)->value('status'))->not->toBe('cancelled');

    // Logout exposes none of the account state to the fresh guest
    // scope, and the saves themselves are untouched.
    $this->post('/account/logout')->assertRedirect('/account/login');
    $this->get('/wishlist')->assertOk()
        ->assertDontSee('Studio Lamp')
        ->assertDontSee('Studio Lighting Guide');
    expect(DB::table('wishlist_items')->count())->toBe(3);

    // A different browser's guest token resolves to its own empty
    // scope: the first browser's list was consumed into Alice's
    // account at login and cannot be reached from here, and owner
    // hints in the request are ignored — the owner is never input.
    $this->withSession(['guest_identity' => 'browser-two-token']);
    expect($this->getJson('/store-api/v1/wishlist')->assertOk()->json('items'))->toBe([]);
    expect($this->getJson('/store-api/v1/wishlist?customer_id='.$alice->id)->assertOk()->json('items'))->toBe([]);
    expect(app(ReadWishlist::class)->snapshot(WishlistOwner::guest('browser-two-token'))['items'])->toBe([]);
    expect(DB::table('wishlists')->count())->toBe(2);
});

it('ShoppingToolsIsolation degrades every surface consistently when a product is unpublished mid-journey', function (): void {
    $catalog = wishlistCatalog();
    $lamp = $catalog['physical_variant'];
    $guide = $catalog['digital_variant'];
    $guest = WishlistOwner::guest('iso-guest-token');
    app(SaveWishlistItem::class)->save($guest, ['variant_id' => $lamp]);
    alertSubscribe('iso-watch@example.test', $lamp, ['stock']);

    // A restock queues the alert while everything is listed.
    alertSetStock($catalog, 0);
    alertSetStock($catalog, 3);
    $intent = DB::table('watch_alert_intents')->first();
    expect($intent)->not->toBeNull()->and($intent->status)->toBe('queued');

    // The merchant unpublishes the lamp mid-journey.
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['status' => 'draft']);

    // Wishlist: the save survives as a bare placeholder, leaking no
    // title, SKU, slug or price of the now-draft product.
    $snapshot = app(ReadWishlist::class)->snapshot($guest);
    $entry = collect($snapshot['items'])->firstWhere('state', 'removed');
    expect($entry)->not->toBeNull()
        ->and($entry)->not->toHaveKeys(['variant_id', 'title', 'sku', 'url', 'money']);
    expect(json_encode($snapshot, JSON_THROW_ON_ERROR))
        ->not->toContain('Studio Lamp', 'LAMP-001', 'studio-lamp', 'USD 24.99');

    // Product page: gone, with the same generic face as any unknown
    // product.
    $this->get('/products/studio-lamp')->assertNotFound();

    // Compare: removing the unpublished selection leaves fewer than
    // two columns, so the surface refuses through the generic
    // validation envelope and leaks nothing either.
    $comparison = $this->getJson('/store-api/v1/compare?'.http_build_query(['items' => [$lamp, $guide]]));
    $comparison->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION');
    expect($comparison->getContent())->not->toContain('Studio Lamp', 'LAMP-001');

    // Alert: the queued intent is cancelled at send time by the
    // listing recheck, and no mail leaves.
    alertDeliver((string) $intent->id);
    $row = DB::table('watch_alert_intents')->where('id', $intent->id)->first();
    expect($row->status)->toBe('cancelled')->and($row->error_code)->toBe('no_longer_listed');
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);

    // Republishing restores the saved entry in full: the degradation
    // was a read-time state, never a deletion.
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['status' => 'published']);
    $restored = collect(app(ReadWishlist::class)->snapshot($guest)['items'])->firstWhere('variant_id', $lamp);
    expect($restored['state'])->toBe('listed')->and($restored['title'])->toBe('Studio Lamp');
});
