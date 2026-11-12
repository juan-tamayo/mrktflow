<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\ThemesContent\Actions\PublishContent;
use App\Domain\ThemesContent\Actions\SaveContent;
use App\Domain\ThemesContent\Actions\SaveRedirect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\CustomerRegistration;

beforeEach(function (): void {
    $this->withoutVite();
});

/** @param  array<string, mixed>  $overrides */
function t070PageInput(array $overrides = []): array
{
    return array_replace([
        'title' => 'About the studio',
        'slug' => 'about',
        'status' => 'published',
    ], $overrides);
}

/** @param  array<string, mixed>  $overrides */
function t070ArticleInput(array $overrides = []): array
{
    return array_replace([
        'title' => 'Choosing a desk lamp',
        'slug' => 'desk-lamp-guide',
        'excerpt' => 'Start with task lighting at the work surface.',
        'status' => 'published',
    ], $overrides);
}

/** @param  list<string>|null  $permissions */
function t070StaffAs(StaffAccount $staff, string $role, ?array $permissions = null): StaffAccount
{
    DB::table('staff_accounts')->where('id', $staff->id)->update([
        'role' => $role,
        'permissions' => json_encode($permissions ?? StaffPermissions::forRole($role)),
    ]);

    return $staff->fresh();
}

/**
 * @return array{owner: StaffAccount, product: array{id: string, slug: string, version: int, status: string, type: string}, category: array{id: string, path: string, version: int}, variant: string, page: array{id: string, path: string, version: int, status: string}, article: array{id: string, path: string, version: int, status: string}}
 */
function t070LiveCatalog(): array
{
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $category = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $product = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published',
        'category_ids' => [$category['id']],
    ]));
    app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'title' => 'Secret Draft Lamp',
        'slug' => 'secret-draft-lamp',
        'sku' => 'LAMP-002',
        'status' => 'draft',
        'category_ids' => [$category['id']],
    ]));
    $page = app(SaveContent::class)->savePage($owner, t070PageInput());
    $article = app(SaveContent::class)->saveArticle($owner, t070ArticleInput());
    app(SaveContent::class)->savePage($owner, t070PageInput([
        'title' => 'Draft shipping policy',
        'slug' => 'shipping-policy',
        'status' => 'draft',
    ]));

    return [
        'owner' => $owner,
        'product' => $product,
        'category' => $category,
        'variant' => (string) DB::table('product_variants')->where('product_id', $product['id'])->value('id'),
        'page' => $page,
        'article' => $article,
    ];
}

it('StorefrontSeo metadata sitemap and structured data reflect live public content', function (): void {
    $live = t070LiveCatalog();
    $origin = rtrim((string) config('app.url'), '/');

    $product = $this->get('/products/studio-lamp?utm_campaign=ignored');
    $product->assertOk()
        ->assertSee('Studio Lamp', false)
        ->assertSee('<link rel="canonical" href="'.$origin.'/products/studio-lamp">', false)
        ->assertSee('<meta property="og:url" content="'.$origin.'/products/studio-lamp">', false)
        ->assertSee('<meta property="og:title" content="Studio Lamp">', false)
        ->assertSee('<meta property="og:type" content="product">', false)
        ->assertSee('"@type":"Product"', false)
        ->assertSee('"@type":"Offer"', false)
        ->assertSee('"price":"24.99"', false)
        ->assertSee('"priceCurrency":"USD"', false)
        ->assertSee('"@type":"BreadcrumbList"', false)
        ->assertDontSee('aggregateRating', false)
        ->assertDontSee('reviewCount', false)
        ->assertDontSee('ratingValue', false);
    expect($product->headers->get('Cache-Control'))->toContain('public')
        ->and($product->headers->get('X-Robots-Tag'))->toBe('index, follow');

    $this->get('/categories/lighting')->assertOk()
        ->assertSee('<link rel="canonical" href="'.$origin.'/categories/lighting">', false)
        ->assertSee('"@type":"BreadcrumbList"', false);

    $this->get('/pages/about')->assertOk()
        ->assertSee('About the studio')
        ->assertSee('<link rel="canonical" href="'.$origin.'/pages/about">', false)
        ->assertDontSee('Draft shipping policy');
    $this->get('/journal/desk-lamp-guide')->assertOk()
        ->assertSee('Choosing a desk lamp')
        ->assertSee('Start with task lighting at the work surface.')
        ->assertSee('<link rel="canonical" href="'.$origin.'/journal/desk-lamp-guide">', false);

    $this->get('/pages/shipping-policy')->assertNotFound()->assertDontSee('Draft shipping policy');
    $this->get('/products/secret-draft-lamp')->assertNotFound()->assertDontSee('Secret Draft Lamp');

    $sitemap = $this->get('/sitemap.xml');
    $sitemap->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    expect($sitemap->headers->get('Cache-Control'))->toContain('public');
    $xml = $sitemap->getContent();
    expect($xml)->toContain($origin.'/products/studio-lamp')
        ->toContain($origin.'/categories/lighting')
        ->toContain($origin.'/pages/about')
        ->toContain($origin.'/journal/desk-lamp-guide')
        ->not->toContain('/products/secret-draft-lamp')
        ->not->toContain('/pages/shipping-policy')
        ->not->toContain('/cart')
        ->not->toContain('/checkout')
        ->not->toContain('/account')
        ->not->toContain('/search')
        ->not->toContain('/admin/preview')
        ->not->toContain($origin.'</loc>')
        ->not->toContain($origin.'/</loc>');

    $this->get('/search')->assertOk()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    expect($this->get('/search')->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');

    $this->get('/')->assertStatus(503)->assertDontSee('About the studio');
    expect($live['variant'])->not->toBe('');
});

it('StorefrontSeo slug redirects collapse chains and refuse loops', function (): void {
    $live = t070LiveCatalog();
    $owner = $live['owner'];

    $renamed = app(SaveContent::class)->savePage($owner, t070PageInput([
        'id' => $live['page']['id'],
        'version' => $live['page']['version'],
        'slug' => 'studio',
        'status' => 'draft',
    ]));
    app(PublishContent::class)->publish($owner, [
        'kind' => 'page',
        'id' => $renamed['id'],
        'version' => $renamed['version'],
    ]);
    $this->get('/pages/about')->assertRedirect('/pages/studio');
    $this->followingRedirects()->get('/pages/about')->assertOk()->assertSee('About the studio');

    $again = app(SaveContent::class)->savePage($owner, t070PageInput([
        'id' => $renamed['id'],
        'version' => $renamed['version'],
        'slug' => 'lighting-studio',
        'status' => 'draft',
    ]));
    app(PublishContent::class)->publish($owner, [
        'kind' => 'page',
        'id' => $again['id'],
        'version' => $again['version'],
    ]);
    $this->get('/pages/about')->assertRedirect('/pages/lighting-studio');
    $this->get('/pages/studio')->assertRedirect('/pages/lighting-studio');

    expect(fn () => app(SaveRedirect::class)->save('/pages/lighting-studio', '/pages/about'))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveRedirect::class)->save('/pages/lighting-studio', '/pages/lighting-studio'))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveRedirect::class)->save('/pages/ghost', '/cart'))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveRedirect::class)->save('/pages/ghost', '/checkout'))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveRedirect::class)->save('/pages/ghost', '/account'))
        ->toThrow(ValidationException::class);

    $product = app(SaveProduct::class)->save($owner, catalogProductInput(catalogReadyImage($owner->id), [
        'id' => $live['product']['id'],
        'version' => $live['product']['version'],
        'slug' => 'bench-lamp',
        'status' => 'published',
        'category_ids' => [$live['category']['id']],
    ]));
    $this->get('/products/studio-lamp')->assertRedirect('/products/bench-lamp');
    $this->followingRedirects()->get('/products/studio-lamp')->assertOk()->assertSee('Studio Lamp');
    expect($product['slug'])->toBe('bench-lamp');

    $this->get('/')->assertStatus(503);
});

it('StorefrontSeo keeps private preview and two-user responses out of shared cache', function (): void {
    $live = t070LiveCatalog();
    $alice = app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), [
        'name' => 'Alice Shopper',
        'email' => 'alice-seo@example.test',
    ]));
    $bob = app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), [
        'name' => 'Bob Shopper',
        'email' => 'bob-seo@example.test',
    ]));

    $guestProduct = $this->get('/products/studio-lamp');
    $guestProduct->assertOk()->assertSee('Studio Lamp');
    expect($guestProduct->headers->get('Cache-Control'))->toContain('public')
        ->and($guestProduct->headers->get('Cache-Control'))->not->toContain('no-store')
        ->and($guestProduct->getContent())->not->toContain('Alice Shopper')
        ->and($guestProduct->getContent())->not->toContain('Bob Shopper');

    $this->post('/cart/lines', [
        'variant_id' => $live['variant'],
        'quantity' => 1,
        'expected_revision' => 1,
    ])->assertRedirect();
    $personalized = $this->get('/products/studio-lamp');
    $personalized->assertOk()->assertSee('Studio Lamp');
    expect($personalized->headers->get('Cache-Control'))->toContain('private')
        ->and($personalized->headers->get('Cache-Control'))->toContain('no-store');

    $this->flushSession();
    $this->app['auth']->forgetGuards();

    $this->actingAs($alice, 'customer')->withSession(['customer_session_version' => 1]);
    $aliceAccount = $this->get('/account');
    $aliceAccount->assertOk()->assertSee('Alice Shopper')->assertDontSee('Bob Shopper');
    expect($aliceAccount->headers->get('Cache-Control'))->toContain('private')
        ->and($aliceAccount->headers->get('Cache-Control'))->toContain('no-store')
        ->and($aliceAccount->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
    $aliceProduct = $this->get('/products/studio-lamp');
    expect($aliceProduct->headers->get('Cache-Control'))->toContain('no-store');

    $this->flushSession();
    $this->app['auth']->forgetGuards();
    $this->actingAs($bob, 'customer')->withSession(['customer_session_version' => 1]);
    $bobAccount = $this->get('/account');
    $bobAccount->assertOk()->assertSee('Bob Shopper')->assertDontSee('Alice Shopper');
    expect($bobAccount->headers->get('Cache-Control'))->toContain('no-store');

    $cart = $this->get('/cart');
    expect($cart->headers->get('Cache-Control'))->toContain('no-store')
        ->and($cart->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
    $checkout = $this->get('/checkout');
    expect($checkout->headers->get('Cache-Control'))->toContain('no-store')
        ->and($checkout->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');

    $this->flushSession();
    $this->app['auth']->forgetGuards();
    $reader = t070StaffAs($live['owner'], 'content_editor', ['admin.access', 'content.read']);
    $this->actingAs($reader, 'staff')->withSession(catalogStaffSession($reader));
    $preview = $this->get('/admin/preview/pages/'.$live['page']['id']);
    $preview->assertOk()->assertSee('About the studio');
    expect($preview->headers->get('Cache-Control'))->toContain('no-store')
        ->and($preview->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');

    $this->get('/')->assertStatus(503);
});

it('StorefrontSeo HTTP keeps privileged staff behind two-factor', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/preview/pages/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
        ->assertRedirect('/admin/two-factor');
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper',
        'email' => 'seo-shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $this->flushSession();
    $this->app['auth']->forgetGuards();
    $this->actingAs($customer, 'customer')->getJson('/admin/preview/pages/'.$customer->id)->assertUnauthorized();
});
