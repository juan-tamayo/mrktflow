<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;

beforeEach(function (): void {
    $this->withoutVite();
});

it('keeps the closed page on the root before installation completes', function (): void {
    $this->get('/')->assertStatus(503)->assertSee('This store is getting ready');
});

it('keeps GET / closed after installation while published catalog pages stay reachable', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $category = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    app(SaveProduct::class)->save($owner, catalogProductInput($image, [
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

    $this->get('/')->assertStatus(503)
        ->assertSee('This store is getting ready')
        ->assertDontSee('Studio Lamp')
        ->assertDontSee('Secret Draft Lamp');
    $this->get('/categories/lighting')->assertOk()
        ->assertSee('Lighting')
        ->assertSee('Studio Lamp')
        ->assertSee('USD 24.99')
        ->assertDontSee('Secret Draft Lamp');
    $this->get('/products/studio-lamp')->assertOk()
        ->assertSee('Studio Lamp')
        ->assertSee('/products/studio-lamp', false);
});

it('wraps catalog pages in the same shop masthead and footer', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $category = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published',
        'category_ids' => [$category['id']],
    ]));

    foreach (['/categories/lighting', '/products/studio-lamp', '/cart', '/search'] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSee('Example Supply')
            ->assertSee('nav', false)
            ->assertSee('/categories/lighting', false)
            ->assertSee('MRKTFlow');
    }
});

it('runs the security headers after proxy trust so proxied HTTPS is accepted', function (): void {
    $kernel = app(Kernel::class);
    $property = new ReflectionProperty($kernel, 'middleware');
    $property->setAccessible(true);
    $stack = $property->getValue($kernel);
    $proxies = array_search(TrustProxies::class, $stack, true);
    $headers = array_search(SecurityHeaders::class, $stack, true);
    expect($proxies)->toBeInt()->and($headers)->toBeInt()->and($proxies)->toBeLessThan($headers);
});

it('trusts the reverse proxy forwarded protocol for storefront links', function (): void {
    catalogInstalledOwner();

    $page = $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('/search');
    $page->assertOk()->assertSee('href="https://', false);
});
