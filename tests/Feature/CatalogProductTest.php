<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\Catalog\Exceptions\CatalogProductNotFound;
use App\Domain\Catalog\Exceptions\CatalogProductUnpublished;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Platform\Actions\StoreMedia;
use App\Domain\Platform\Contracts\MediaUploadAuthorization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

it('CatalogProduct keeps drafts out of the public catalog while staff can save them', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $saved = app(SaveProduct::class)->save($owner, catalogProductInput($image));
    expect($saved['status'])->toBe('draft');
    expect($saved['type'])->toBe('physical');
    expect(fn () => app(CatalogReader::class)->publicProduct($saved['id']))->toThrow(CatalogProductUnpublished::class);
    expect(fn () => app(CatalogReader::class)->publicProduct('studio-lamp'))->toThrow(CatalogProductUnpublished::class);
    $staffView = app(CatalogReader::class)->staffProduct($saved['id']);
    expect($staffView['title'])->toBe('Studio Lamp');
    expect($staffView['variant']['sku'])->toBe('LAMP-001');
    expect($staffView['variant']['money'])->toBe(['amount' => 2499, 'currency' => 'USD', 'exponent' => 2]);
    expect($staffView['image']['url'])->toStartWith('/storage/media/');
    expect(json_encode($staffView))->not->toContain('original_key', 'original_name', $owner->password);
    $this->get('/')->assertStatus(503)->assertDontSee('Studio Lamp')->assertDontSee('LAMP-001');
    $this->get('/products/studio-lamp')->assertNotFound()->assertDontSee('Studio Lamp');
    expect(app(CatalogReader::class)->publishedCount())->toBe(0);
});

it('CatalogProduct publishes a simple physical product with one SKU price and image', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $draft = app(SaveProduct::class)->save($owner, catalogProductInput($image));
    $published = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'id' => $draft['id'], 'version' => $draft['version'], 'status' => 'published',
    ]));
    expect($published['status'])->toBe('published');
    $public = app(CatalogReader::class)->publicProduct($published['slug']);
    expect($public['id'])->toBe($published['id']);
    expect($public['title'])->toBe('Studio Lamp');
    expect($public['type'])->toBe('physical');
    expect($public['variant']['sku'])->toBe('LAMP-001');
    expect($public['variant']['money']['amount'])->toBe(2499);
    expect($public['image']['alt'])->toBe('Brushed steel studio lamp');
    expect($public['image']['url'])->toMatch('#^/storage/media/[0-9a-f-]{36}/[a-f0-9]{64}\.png$#D');
    expect(json_encode($public))->not->toContain('original_key', 'media/'.$image.'/original', 'supplier');
    expect(app(CatalogReader::class)->publishedCount())->toBe(1);
    $this->get('/')->assertStatus(503)->assertDontSee('Studio Lamp');
    $this->get('/products/studio-lamp')->assertOk()->assertSee('Studio Lamp')->assertSee('Add to cart');
});

it('CatalogProduct HTTP keeps privileged staff behind two-factor before catalog admin', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/products')->assertRedirect('/admin/two-factor');
    $this->get('/admin/products/create')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/products', ['product' => ['title' => 'Denied']])->assertForbidden();
});

it('CatalogProduct HTTP lets authorized staff create a draft and publish it', function (): void {
    $owner = catalogInstalledOwner();
    $this->get('/admin/products')->assertRedirect('/admin/login');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/products/create')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('ProductEdit'));
    $this->post('/admin/products', [
        'product' => [
            'title' => 'Oak Desk', 'slug' => 'oak-desk', 'description' => 'Solid oak writing desk.',
            'status' => 'draft', 'sku' => 'DESK-01', 'price_amount' => 12900, 'image_alt' => 'Oak desk from the front',
        ],
        'image' => UploadedFile::fake()->image('desk.png', 40, 40),
    ])->assertRedirect();
    $id = DB::table('products')->value('id');
    expect(DB::table('products')->value('status'))->toBe('draft');
    expect(fn () => app(CatalogReader::class)->publicProduct($id))->toThrow(CatalogProductUnpublished::class);
    $version = (int) DB::table('products')->value('version');
    $this->from('/admin/products/'.$id)->patch('/admin/products/'.$id, [
        'product' => [
            'title' => 'Oak Desk', 'slug' => 'oak-desk', 'description' => 'Solid oak writing desk.',
            'status' => 'published', 'sku' => 'DESK-01', 'price_amount' => 12900,
            'image_alt' => 'Oak desk from the front', 'version' => $version,
        ],
    ])->assertRedirect('/admin/products/'.$id);
    expect(DB::table('products')->value('status'))->toBe('published');
    expect(app(CatalogReader::class)->publicProduct('oak-desk')['title'])->toBe('Oak Desk');
    $this->get('/admin/products')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ProductIndex')->where('products.data.0.title', 'Oak Desk')->where('products.data.0.status', 'published'));
    $this->from('/admin/products/create')->post('/admin/products', [
        'title' => 'Walnut Stool', 'slug' => 'walnut-stool', 'description' => 'A stool.',
        'status' => 'published', 'sku' => 'STOOL-01', 'price_amount' => 4500,
        'image_alt' => 'Walnut stool',
        'image' => UploadedFile::fake()->image('stool.png', 40, 40),
    ])->assertRedirect();
    expect(app(CatalogReader::class)->publicProduct('walnut-stool')['title'])->toBe('Walnut Stool');
});

it('CatalogProduct HTTP denies guests customers and staff without catalog write', function (): void {
    $owner = catalogInstalledOwner();
    $this->postJson('/admin/products', ['product' => ['title' => 'Denied']])->assertUnauthorized();
    $customer = new CustomerAccount;
    $customer->forceFill(['name' => 'Shopper', 'email' => 'shopper@example.test', 'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1])->save();
    $this->actingAs($customer, 'customer')->postJson('/admin/products', ['product' => ['title' => 'Denied']])->assertUnauthorized();
    foreach (['content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()))
            ->postJson('/admin/products', ['product' => catalogProductInput(catalogReadyImage($owner->id))])->assertForbidden();
        $this->getJson('/admin/products/create')->assertForbidden();
    }
    DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => 'manager', 'permissions' => json_encode(StaffPermissions::forRole('manager'))]);
    $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()))
        ->getJson('/admin/products')->assertOk();
});

it('CatalogProduct rejects duplicate SKUs unsafe money client currency and unpublished images', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    app(SaveProduct::class)->save($owner, catalogProductInput($image));
    expect(fn () => app(SaveProduct::class)->save($owner, catalogProductInput($image, ['slug' => 'other-lamp', 'sku' => 'lamp-001'])))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveProduct::class)->save($owner, catalogProductInput($image, ['slug' => 'other-lamp', 'sku' => 'LAMP-002', 'price_amount' => -1])))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'slug' => 'other-lamp', 'sku' => 'LAMP-002', 'price_amount' => 100, 'currency' => 'EUR',
    ])))->toThrow(ValidationException::class);
    $pending = app(StoreMedia::class)->store(UploadedFile::fake()->image('pending.png', 16, 16), 'public_image', new class($owner->id) implements MediaUploadAuthorization
    {
        public function __construct(private readonly string $ownerId) {}

        public function authorizeUpload(): string
        {
            return $this->ownerId;
        }
    });
    expect(fn () => app(SaveProduct::class)->save($owner, catalogProductInput($pending, ['slug' => 'pending-lamp', 'sku' => 'LAMP-003'])))
        ->toThrow(ValidationException::class);
    expect(fn () => app(CatalogReader::class)->publicProduct('missing-product'))->toThrow(CatalogProductNotFound::class);
    expect(DB::table('products')->count())->toBe(1);
});

it('CatalogProduct HTTP rejects forged saves and does not flash nested product payloads', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->app->instance('env', 'local');
    $this->postJson('/admin/products', ['product' => ['title' => 'Forged']])->assertStatus(419);
    $this->app->instance('env', 'testing');
    $this->from('/admin/products/create')->post('/admin/products', ['product' => [
        'title' => '', 'slug' => 'admin', 'description' => 'x', 'status' => 'published', 'sku' => '', 'price_amount' => 0, 'image_alt' => '',
    ]])->assertRedirect('/admin/products/create')->assertSessionHasErrors()->assertSessionMissing('_old_input.product');
});
