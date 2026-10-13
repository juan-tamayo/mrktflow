<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\Catalog\Exceptions\CatalogCategoryNotFound;
use App\Domain\Catalog\Exceptions\CatalogCategoryUnpublished;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\StaffPermissions;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

uses(DatabaseMigrations::class);
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

it('CatalogCategory rejects cycles path collisions and reserved routes', function (): void {
    $owner = catalogInstalledOwner();
    $lighting = app(SaveCategory::class)->save($owner, catalogCategoryInput());
    expect($lighting['path'])->toBe('lighting');
    $lamps = app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'title' => 'Lamps', 'slug' => 'lamps', 'parent_id' => $lighting['id'], 'description' => 'Task lamps.',
    ]));
    expect($lamps['path'])->toBe('lighting/lamps');
    $desk = app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'title' => 'Desk lamps', 'slug' => 'desk', 'parent_id' => $lamps['id'], 'description' => 'Desk lamps.',
    ]));
    expect($desk['path'])->toBe('lighting/lamps/desk');
    expect(fn () => app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'id' => $lighting['id'], 'version' => $lighting['version'], 'parent_id' => $desk['id'],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'id' => $lighting['id'], 'version' => $lighting['version'], 'parent_id' => $lighting['id'],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'title' => 'Other lamps', 'slug' => 'lamps', 'parent_id' => $lighting['id'], 'description' => 'Collision.',
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'title' => 'Admin', 'slug' => 'admin', 'description' => 'Reserved.',
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'title' => 'Cart', 'slug' => 'cart', 'description' => 'Reserved.',
    ])))->toThrow(ValidationException::class);
    expect(DB::table('categories')->count())->toBe(3);
});

it('CatalogCategory keeps drafts private and renders published empty categories', function (): void {
    $owner = catalogInstalledOwner();
    $draft = app(SaveCategory::class)->save($owner, catalogCategoryInput());
    expect(fn () => app(CatalogReader::class)->publicCategory('lighting'))->toThrow(CatalogCategoryUnpublished::class);
    $this->get('/categories/lighting')->assertNotFound()->assertDontSee('Lighting');
    $this->get('/')->assertStatus(503)->assertDontSee('Lighting');
    $published = app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'id' => $draft['id'], 'version' => $draft['version'], 'status' => 'published',
    ]));
    expect($published['status'])->toBe('published');
    $public = app(CatalogReader::class)->publicCategory('lighting');
    expect($public['title'])->toBe('Lighting');
    expect($public['products'])->toBe([]);
    $page = $this->get('/categories/lighting');
    $page->assertOk()->assertSee('Lighting')->assertSee('No products in this category yet')->assertDontSee('Add to cart');
    $page->assertSee('Lighting', false);
    $this->get('/')->assertStatus(503)->assertDontSee('Lighting');
});

it('CatalogCategory lists published products with breadcrumbs and hides drafts', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $lighting = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $lamps = app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'title' => 'Lamps', 'slug' => 'lamps', 'parent_id' => $lighting['id'], 'status' => 'published', 'description' => 'Task lamps.',
    ]));
    $draft = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'category_ids' => [$lamps['id']],
    ]));
    $this->get('/categories/lighting/lamps')->assertOk()->assertDontSee('Studio Lamp');
    $this->get('/products/studio-lamp')->assertNotFound()->assertDontSee('Studio Lamp');
    app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'id' => $draft['id'], 'version' => $draft['version'], 'status' => 'published', 'category_ids' => [$lamps['id']],
    ]));
    $category = $this->get('/categories/lighting/lamps');
    $category->assertOk()->assertSee('Studio Lamp')->assertSee('Lamps')->assertSee('Lighting');
    $category->assertSee('/products/studio-lamp', false);
    $product = $this->get('/products/studio-lamp');
    $product->assertOk()->assertSee('Studio Lamp')->assertSee('USD 24.99')->assertSee('Physical product');
    $product->assertSee('Lighting')->assertSee('Lamps')->assertDontSee('Add to cart')->assertDontSee('Theme preview');
    $product->assertSee('/categories/lighting/lamps', false);
    $this->get('/products/missing-lamp')->assertNotFound()->assertDontSee('Studio Lamp');
    $this->get('/')->assertStatus(503)->assertDontSee('Studio Lamp');
    expect(json_encode(app(CatalogReader::class)->publicCategory('lighting/lamps')))->not->toContain('original_key', $owner->password);
});

it('CatalogCategory records validated redirects when a slug changes', function (): void {
    $owner = catalogInstalledOwner();
    $lighting = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $lamps = app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'title' => 'Lamps', 'slug' => 'lamps', 'parent_id' => $lighting['id'], 'status' => 'published', 'description' => 'Task lamps.',
    ]));
    $renamed = app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'id' => $lighting['id'], 'version' => $lighting['version'], 'title' => 'House lighting',
        'slug' => 'house-lighting', 'status' => 'published',
    ]));
    expect($renamed['path'])->toBe('house-lighting');
    expect(app(CatalogReader::class)->publicCategory('house-lighting/lamps')['id'])->toBe($lamps['id']);
    $this->get('/categories/lighting')->assertRedirect('/categories/house-lighting');
    $this->get('/categories/lighting/lamps')->assertRedirect('/categories/house-lighting/lamps');
    $this->followingRedirects()->get('/categories/lighting')->assertOk()->assertSee('House lighting');
});

it('CatalogCategory HTTP keeps privileged staff behind two-factor and denies outsiders', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/categories')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/categories', ['category' => ['title' => 'Denied']])->assertForbidden();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->app->instance('env', 'local');
    $this->postJson('/admin/categories', ['category' => ['title' => 'Forged']])->assertStatus(419);
    $this->app->instance('env', 'testing');
    $this->from('/admin/categories/create')->post('/admin/categories', ['category' => [
        'title' => '', 'slug' => 'admin', 'description' => 'x', 'status' => 'published',
    ]])->assertRedirect('/admin/categories/create')->assertSessionHasErrors()->assertSessionMissing('_old_input.category');
    $this->flushSession();
    $this->app['auth']->forgetGuards();
    $this->postJson('/admin/categories', ['category' => catalogCategoryInput()])->assertUnauthorized();
    $customer = new CustomerAccount;
    $customer->forceFill(['name' => 'Shopper', 'email' => 'shopper@example.test', 'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1])->save();
    $this->actingAs($customer, 'customer')->postJson('/admin/categories', ['category' => catalogCategoryInput()])->assertUnauthorized();
    foreach (['content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()))
            ->postJson('/admin/categories', ['category' => catalogCategoryInput(['slug' => $role])])
            ->assertForbidden();
    }
});

it('CatalogCategory HTTP lets authorized staff create publish and assign categories', function (): void {
    $owner = catalogInstalledOwner();
    $this->get('/admin/categories')->assertRedirect('/admin/login');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/categories/create')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('CategoryEdit'));
    $this->post('/admin/categories', [
        'category' => ['title' => 'Lighting', 'slug' => 'lighting', 'description' => 'Lamps.', 'status' => 'published'],
    ])->assertRedirect();
    $id = (string) DB::table('categories')->value('id');
    expect(DB::table('categories')->value('status'))->toBe('published');
    expect(app(CatalogReader::class)->publicCategory('lighting')['title'])->toBe('Lighting');
    $this->get('/admin/categories')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('CategoryIndex')->where('categories.0.title', 'Lighting')->where('categories.0.path', 'lighting'));
    $this->get('/admin/products/create')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ProductEdit')->where('categories.0.id', $id));
    $this->post('/admin/products', [
        'product' => [
            'title' => 'Oak Desk', 'slug' => 'oak-desk', 'description' => 'Solid oak writing desk.',
            'status' => 'published', 'sku' => 'DESK-01', 'price_amount' => 12900,
            'image_alt' => 'Oak desk from the front', 'category_ids' => [$id],
        ],
        'image' => UploadedFile::fake()->image('desk.png', 40, 40),
    ])->assertRedirect();
    $this->get('/categories/lighting')->assertOk()->assertSee('Oak Desk');
    $this->get('/products/oak-desk')->assertOk()->assertSee('Oak Desk')->assertSee('Lighting');
});

it('CatalogCategory public lookup treats missing unpublished and unknown paths as not found', function (): void {
    catalogInstalledOwner();
    expect(fn () => app(CatalogReader::class)->publicCategory('missing'))->toThrow(CatalogCategoryNotFound::class);
    $this->get('/categories/missing')->assertNotFound();
    $this->get('/categories/../.env')->assertNotFound();
});
