<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\ArchiveProduct;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\ShoppingTools\Actions\MergeWishlist;
use App\Domain\ShoppingTools\Actions\ReadWishlist;
use App\Domain\ShoppingTools\Actions\SaveWishlistItem;
use App\Domain\ShoppingTools\Exceptions\WishlistItemNotFound;
use App\Domain\ShoppingTools\Support\WishlistOwner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\CustomerRegistration;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
    Carbon::setTestNow(Carbon::parse('2026-10-16T18:00:00Z')->utc());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{owner: mixed, physical_id: string, physical_variant: string, digital_id: string, digital_variant: string}
 */
function wishlistCatalog(): array
{
    $catalog = cartCatalog();
    app(AdjustStock::class)->adjust($catalog['owner'], [
        'variant_id' => $catalog['physical_variant'],
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 5,
        'low_stock_threshold' => 1,
    ]);

    return $catalog;
}

/** @param array<string, mixed> $overrides */
function wishlistProductUpdate(array $catalog, string $productId, array $overrides): void
{
    $image = (string) DB::table('product_media')->where('product_id', $productId)->where('sort_order', 0)->value('media_asset_id');
    $version = (int) DB::table('products')->where('id', $productId)->value('version');
    app(SaveProduct::class)->save($catalog['owner'], catalogProductInput($image, array_replace([
        'id' => $productId,
        'version' => $version,
        'status' => 'published',
    ], $overrides)));
}

it('ShoppingToolsWishlist persists guest saves server-side, scoped to the guest token, with current price and availability', function (): void {
    $catalog = wishlistCatalog();
    $guest = WishlistOwner::guest('wishlist-guest-token');
    $otherGuest = WishlistOwner::guest('wishlist-other-token');
    $save = app(SaveWishlistItem::class);

    $empty = app(ReadWishlist::class)->snapshot($guest);
    expect($empty['id'])->toBeNull()->and($empty['items'])->toBe([]);

    $saved = $save->save($guest, ['variant_id' => $catalog['physical_variant']]);
    expect($saved['items'])->toHaveCount(1);
    $save->save($guest, ['variant_id' => $catalog['digital_variant']]);

    $snapshot = app(ReadWishlist::class)->snapshot($guest);
    expect($snapshot['items'])->toHaveCount(2);
    $physical = collect($snapshot['items'])->firstWhere('variant_id', $catalog['physical_variant']);
    $digital = collect($snapshot['items'])->firstWhere('variant_id', $catalog['digital_variant']);
    expect($physical['state'])->toBe('listed')
        ->and($physical['title'])->toBe('Studio Lamp')
        ->and($physical['sku'])->toBe('LAMP-001')
        ->and($physical['url'])->toBe('/products/studio-lamp')
        ->and($physical['money']['amount'])->toBe(2499)
        ->and($physical['availability'])->toBe('in_stock')
        ->and($physical['available'])->toBeTrue()
        ->and($digital['state'])->toBe('listed')
        ->and($digital['money']['amount'])->toBe(899);
    expect(json_encode($snapshot, JSON_THROW_ON_ERROR))->not->toContain('wishlist-guest-token', 'guest_token_hash', 'password');

    // A different guest token is a different device: nothing is shared,
    // and the guest scope cannot be enumerated from another scope.
    expect(app(ReadWishlist::class)->snapshot($otherGuest)['items'])->toBe([]);
    expect(DB::table('wishlists')->where('guest_token_hash', hash('sha256', 'wishlist-guest-token'))->count())->toBe(1)
        ->and(DB::table('wishlist_items')->count())->toBe(2);
});

it('ShoppingToolsWishlist treats saving as an idempotent intent that refreshes its timestamp, and removal as owner-scoped', function (): void {
    $catalog = wishlistCatalog();
    $guest = WishlistOwner::guest('wishlist-idem-token');
    $stranger = WishlistOwner::guest('wishlist-stranger-token');
    $save = app(SaveWishlistItem::class);

    $first = $save->save($guest, ['variant_id' => $catalog['physical_variant']]);
    $itemId = $first['items'][0]['id'];
    Carbon::setTestNow(Carbon::parse('2026-10-16T20:30:00Z')->utc());
    $again = $save->save($guest, ['variant_id' => $catalog['physical_variant']]);
    expect($again['items'])->toHaveCount(1)
        ->and($again['items'][0]['id'])->toBe($itemId)
        ->and($again['items'][0]['saved_at'])->toContain('20:30');
    expect(DB::table('wishlist_items')->count())->toBe(1);

    // A foreign owner cannot remove (or even address) the item.
    expect(fn () => $save->remove($stranger, $itemId))->toThrow(WishlistItemNotFound::class);
    expect(fn () => $save->remove($guest, (string) Str::uuid()))->toThrow(WishlistItemNotFound::class);
    expect(DB::table('wishlist_items')->count())->toBe(1);

    $after = $save->remove($guest, $itemId);
    expect($after['items'])->toBe([]);
    expect(DB::table('wishlist_items')->count())->toBe(0);
});

it('ShoppingToolsWishlist only saves listed variants and resolves a product save to its default variant', function (): void {
    $catalog = wishlistCatalog();
    $guest = WishlistOwner::guest('wishlist-listed-token');
    $save = app(SaveWishlistItem::class);

    $byProduct = $save->save($guest, ['product_id' => $catalog['physical_id']]);
    expect($byProduct['items'])->toHaveCount(1)
        ->and($byProduct['items'][0]['variant_id'])->toBe($catalog['physical_variant']);

    $draft = app(SaveProduct::class)->save($catalog['owner'], catalogProductInput(catalogReadyImage($catalog['owner']->id), [
        'title' => 'Unreleased Lamp', 'slug' => 'unreleased-lamp', 'sku' => 'LAMP-DRAFT', 'status' => 'draft',
    ]));
    $draftVariant = (string) DB::table('product_variants')->where('product_id', $draft['id'])->value('id');
    expect(fn () => $save->save($guest, ['variant_id' => $draftVariant]))->toThrow(ValidationException::class);
    expect(fn () => $save->save($guest, ['product_id' => $draft['id']]))->toThrow(ValidationException::class);
    expect(fn () => $save->save($guest, ['variant_id' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(fn () => $save->save($guest, []))->toThrow(ValidationException::class);
    expect(DB::table('wishlist_items')->count())->toBe(1);
});

it('ShoppingToolsWishlist unions guest saves into the account on login without duplicates, keeping the newest intent', function (): void {
    $catalog = wishlistCatalog();
    $guest = WishlistOwner::guest('wishlist-merge-token');
    $customer = cartCustomer();
    $account = WishlistOwner::customer($customer->id);
    $save = app(SaveWishlistItem::class);

    $save->save($account, ['variant_id' => $catalog['physical_variant']]);
    $save->save($account, ['variant_id' => $catalog['digital_variant']]);
    Carbon::setTestNow(Carbon::parse('2026-10-16T21:00:00Z')->utc());
    // The guest re-saves the shared variant later (newer intent) and adds
    // nothing else, so the union is exactly the two account variants.
    $save->save($guest, ['variant_id' => $catalog['physical_variant']]);

    $merged = app(MergeWishlist::class)->merge($guest, $account);
    expect($merged['items'])->toHaveCount(2);
    $physical = collect($merged['items'])->firstWhere('variant_id', $catalog['physical_variant']);
    expect($physical['saved_at'])->toContain('21:00');
    expect(DB::table('wishlist_items')->count())->toBe(2)
        ->and(DB::table('wishlists')->where('guest_token_hash', hash('sha256', 'wishlist-merge-token'))->exists())->toBeFalse();
    expect(app(ReadWishlist::class)->snapshot($guest)['items'])->toBe([]);

    // A repeated login merge is a no-op, and the merge refuses swapped owners.
    $repeated = app(MergeWishlist::class)->merge($guest, $account);
    expect($repeated['items'])->toHaveCount(2);
    expect(DB::table('wishlist_items')->count())->toBe(2);
    expect(fn () => app(MergeWishlist::class)->merge($account, $guest))->toThrow(InvalidArgumentException::class);
});

it('ShoppingToolsWishlist assigns a guest-only list to the account and moves unique guest variants into an existing account list', function (): void {
    $catalog = wishlistCatalog();
    $customer = cartCustomer();
    $account = WishlistOwner::customer($customer->id);
    $save = app(SaveWishlistItem::class);

    $guestOnly = WishlistOwner::guest('wishlist-assign-token');
    $save->save($guestOnly, ['variant_id' => $catalog['digital_variant']]);
    $assigned = app(MergeWishlist::class)->merge($guestOnly, $account);
    expect($assigned['items'])->toHaveCount(1)
        ->and($assigned['items'][0]['variant_id'])->toBe($catalog['digital_variant']);
    expect(DB::table('wishlists')->where('customer_id', $customer->id)->count())->toBe(1)
        ->and(DB::table('wishlists')->where('guest_token_hash', hash('sha256', 'wishlist-assign-token'))->exists())->toBeFalse();

    $secondGuest = WishlistOwner::guest('wishlist-move-token');
    $save->save($secondGuest, ['variant_id' => $catalog['physical_variant']]);
    $moved = app(MergeWishlist::class)->merge($secondGuest, $account);
    expect($moved['items'])->toHaveCount(2);
    expect(collect($moved['items'])->pluck('variant_id')->all())
        ->toContain($catalog['physical_variant'], $catalog['digital_variant']);
    expect(DB::table('wishlist_items')->count())->toBe(2);
});

it('ShoppingToolsWishlist shows current price and stock state for listed items', function (): void {
    $catalog = wishlistCatalog();
    $guest = WishlistOwner::guest('wishlist-current-token');
    $save = app(SaveWishlistItem::class);
    $save->save($guest, ['variant_id' => $catalog['physical_variant']]);

    wishlistProductUpdate($catalog, $catalog['physical_id'], ['price_amount' => 1999]);
    app(AdjustStock::class)->adjust($catalog['owner'], [
        'variant_id' => $catalog['physical_variant'],
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 0,
        'low_stock_threshold' => 1,
    ]);

    $snapshot = app(ReadWishlist::class)->snapshot($guest);
    expect($snapshot['items'])->toHaveCount(1)
        ->and($snapshot['items'][0]['state'])->toBe('listed')
        ->and($snapshot['items'][0]['money']['amount'])->toBe(1999)
        ->and($snapshot['items'][0]['availability'])->toBe('out_of_stock')
        ->and($snapshot['items'][0]['available'])->toBeFalse();
});

it('ShoppingToolsWishlist degrades unpublished and archived saves to a privacy-safe placeholder without leaking catalog content', function (): void {
    $catalog = wishlistCatalog();
    $guest = WishlistOwner::guest('wishlist-privacy-token');
    $save = app(SaveWishlistItem::class);
    $save->save($guest, ['variant_id' => $catalog['physical_variant']]);
    $save->save($guest, ['variant_id' => $catalog['digital_variant']]);

    // Unpublish the physical product: its entry must stop exposing the
    // draft's title, SKU, price and slug immediately.
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['status' => 'draft']);
    $snapshot = app(ReadWishlist::class)->snapshot($guest);
    $physical = collect($snapshot['items'])->firstWhere('variant_id', $catalog['physical_variant']);
    expect($physical)->toBeNull();
    $removed = collect($snapshot['items'])->firstWhere('state', 'removed');
    expect($removed)->not->toBeNull()
        ->and($removed)->not->toHaveKeys(['title', 'sku', 'url', 'money', 'product_id']);
    $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('Studio Lamp', 'LAMP-001', 'studio-lamp', 'USD 24.99');

    // Archiving the digital product degrades it the same way.
    $version = (int) DB::table('products')->where('id', $catalog['digital_id'])->value('version');
    app(ArchiveProduct::class)->archive($catalog['owner'], ['id' => $catalog['digital_id'], 'version' => $version]);
    $snapshot = app(ReadWishlist::class)->snapshot($guest);
    expect(collect($snapshot['items'])->where('state', 'listed')->count())->toBe(0)
        ->and(collect($snapshot['items'])->where('state', 'removed')->count())->toBe(2);
    expect(json_encode($snapshot, JSON_THROW_ON_ERROR))->not->toContain('Studio Lighting Guide', 'GUIDE-001', 'studio-guide', 'USD 8.99');

    // Republishing restores the full entry: the save itself is durable.
    wishlistProductUpdate($catalog, $catalog['physical_id'], ['status' => 'published']);
    $snapshot = app(ReadWishlist::class)->snapshot($guest);
    $restored = collect($snapshot['items'])->firstWhere('variant_id', $catalog['physical_variant']);
    expect($restored['state'])->toBe('listed')->and($restored['title'])->toBe('Studio Lamp');
});

it('ShoppingToolsWishlist keeps one account list across devices and never creates consent or subscription facts by saving', function (): void {
    $catalog = wishlistCatalog();
    $customer = cartCustomer();
    $account = WishlistOwner::customer($customer->id);
    $save = app(SaveWishlistItem::class);
    $contactsBefore = DB::table('delivery_contacts')->count();
    $grantsBefore = DB::table('purpose_grants')->count();

    $save->save($account, ['variant_id' => $catalog['physical_variant']]);
    // The account list is keyed by the customer alone: any session that
    // resolves to this customer (any device) reads and extends the same
    // list, while another customer sees nothing of it.
    $save->save($account, ['variant_id' => $catalog['digital_variant']]);
    expect(app(ReadWishlist::class)->snapshot($account)['items'])->toHaveCount(2);

    $other = cartCustomer(['email' => 'other@example.test']);
    expect(app(ReadWishlist::class)->snapshot(WishlistOwner::customer($other->id))['items'])->toBe([]);
    expect(DB::table('delivery_contacts')->count())->toBe($contactsBefore)
        ->and(DB::table('purpose_grants')->count())->toBe($grantsBefore)
        ->and(DB::table('delivery_suppressions')->count())->toBe(0);
});

it('ShoppingToolsWishlist serves the guest flow over HTTP, merges on login, and hides account saves after logout', function (): void {
    $catalog = wishlistCatalog();
    $customer = cartCustomer();
    $account = WishlistOwner::customer($customer->id);
    app(SaveWishlistItem::class)->save($account, ['variant_id' => $catalog['digital_variant']]);

    // Guest saves over HTTP persist across requests in the same session.
    $this->post('/wishlist/items', ['variant_id' => $catalog['physical_variant']])->assertRedirect('/wishlist');
    $this->get('/wishlist')->assertOk()->assertSee('Studio Lamp')->assertDontSee('Studio Lighting Guide');

    // Login unions the guest save into the account list, once.
    $this->post('/account/login', [
        'email' => CustomerRegistration::input()['email'],
        'password' => CustomerRegistration::input()['password'],
    ])->assertRedirect('/account');
    $this->get('/wishlist')->assertOk()->assertSee('Studio Lamp')->assertSee('Studio Lighting Guide');
    expect(DB::table('wishlist_items')->count())->toBe(2)
        ->and(DB::table('wishlists')->whereNotNull('guest_token_hash')->count())->toBe(0);

    // Logout invalidates the session: the account list is not exposed to
    // the fresh guest scope, and signing back in restores it untouched.
    $this->post('/account/logout')->assertRedirect('/account/login');
    $this->get('/wishlist')->assertOk()->assertDontSee('Studio Lamp')->assertDontSee('Studio Lighting Guide');
    expect(DB::table('wishlist_items')->count())->toBe(2);
    $this->post('/account/login', [
        'email' => CustomerRegistration::input()['email'],
        'password' => CustomerRegistration::input()['password'],
    ])->assertRedirect('/account');
    $this->get('/wishlist')->assertOk()->assertSee('Studio Lamp')->assertSee('Studio Lighting Guide');
});

it('ShoppingToolsWishlist never exposes one customer\'s saves to another over HTTP', function (): void {
    $catalog = wishlistCatalog();
    $first = cartCustomer();
    app(SaveWishlistItem::class)->save(WishlistOwner::customer($first->id), ['variant_id' => $catalog['physical_variant']]);
    $itemId = (string) DB::table('wishlist_items')->value('id');
    $second = cartCustomer(['email' => 'second@example.test', 'name' => 'Second Shopper']);

    $this->post('/account/login', [
        'email' => 'second@example.test',
        'password' => CustomerRegistration::input()['password'],
    ])->assertRedirect('/account');
    $this->get('/wishlist')->assertOk()->assertDontSee('Studio Lamp');
    $this->post('/wishlist/items/'.$itemId.'/delete')->assertNotFound();
    expect(DB::table('wishlist_items')->count())->toBe(1);
    expect($second->id)->not->toBe($first->id);
});
