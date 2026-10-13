<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveVariants;
use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\StaffPermissions;
use Illuminate\Foundation\Testing\DatabaseMigrations;
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

it('CatalogVariant rejects duplicate SKUs and option combinations', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $saved = app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    $input = catalogVariantMatrix($saved['id'], $saved['version'], $image);
    $input['variants'][1]['sku'] = $input['variants'][0]['sku'];
    expect(fn () => app(SaveVariants::class)->save($owner, $input))->toThrow(ValidationException::class);
    $input = catalogVariantMatrix($saved['id'], $saved['version'], $image);
    $input['variants'][1]['options'] = $input['variants'][0]['options'];
    $input['variants'][1]['sku'] = 'LAMP-DUP';
    expect(fn () => app(SaveVariants::class)->save($owner, $input))->toThrow(ValidationException::class);
    expect(DB::table('product_variants')->where('product_id', $saved['id'])->count())->toBe(1);
});

it('CatalogVariant saves unique combinations and lets a shopper see the selected price media and availability', function (): void {
    $owner = catalogInstalledOwner();
    $productImage = catalogReadyImage($owner->id);
    $pineImage = catalogReadyImage($owner->id);
    $saved = app(SaveProduct::class)->save($owner, catalogProductInput($productImage, ['status' => 'published']));
    $updated = app(SaveVariants::class)->save($owner, catalogVariantMatrix($saved['id'], $saved['version'], $pineImage));
    expect($updated['variant_count'])->toBe(3);
    $public = app(CatalogReader::class)->publicProduct('studio-lamp');
    expect($public['variants'])->toHaveCount(3);
    expect($public['option_axes'][0]['name'])->toBe('Color');
    expect($public['option_axes'][1]['values'])->toBe(['S', 'M']);
    $pine = collect($public['variants'])->firstOrFail(fn (array $variant): bool => $variant['sku'] === 'LAMP-PINE-S');
    $unavailable = collect($public['variants'])->firstOrFail(fn (array $variant): bool => $variant['sku'] === 'LAMP-PINE-M');
    expect($pine['available'])->toBeTrue();
    expect($pine['money']['amount'])->toBe(2499);
    expect($pine['image']['url'])->toMatch('#^/storage/media/[0-9a-f-]{36}/[a-f0-9]{64}\.png$#D');
    expect($unavailable['available'])->toBeFalse();
    expect(json_encode($public))->not->toContain('original_key', $owner->password);
    $this->get('/')->assertStatus(503)->assertDontSee('Studio Lamp');
    $page = $this->get('/products/studio-lamp?variant='.$pine['id']);
    $page->assertOk()->assertSee('Studio Lamp')->assertSee('USD 24.99')->assertSee('Pine / S')->assertDontSee('Add to cart');
    $unavailablePage = $this->get('/products/studio-lamp?variant='.$unavailable['id']);
    $unavailablePage->assertOk()->assertSee('This combination is unavailable')->assertDontSee('<strong>USD 25.99</strong>', false);
    $this->getJson('/products/studio-lamp?variant=not-a-variant')->assertUnprocessable();
    $missing = $this->get('/products/studio-lamp');
    $missing->assertOk()->assertSee('Choose a finish and size to see the current price');
});

it('CatalogVariant HTTP keeps privileged staff behind two-factor and rejects customers', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $saved = app(SaveProduct::class)->save($owner, catalogProductInput($image));
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/products/'.$saved['id'])->assertRedirect('/admin/two-factor');
    $this->putJson('/admin/products/'.$saved['id'].'/variants', catalogVariantMatrix($saved['id'], $saved['version'], $image))->assertForbidden();
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill(['name' => 'Shopper', 'email' => 'shopper@example.test', 'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])->putJson('/admin/products/'.$saved['id'].'/variants', catalogVariantMatrix($saved['id'], $saved['version'], $image))->assertUnauthorized();
});

it('CatalogVariant HTTP lets authorized staff replace the variant matrix', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $saved = app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/products/'.$saved['id'])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ProductEdit')
        ->has('product.variants', 1)
        ->where('product.variants.0.sku', 'LAMP-001'));
    $this->put('/admin/products/'.$saved['id'].'/variants', catalogVariantMatrix($saved['id'], $saved['version'], $image))
        ->assertRedirect('/admin/products/'.$saved['id']);
    expect(DB::table('product_variants')->where('product_id', $saved['id'])->count())->toBe(3);
    $this->from('/admin/products/'.$saved['id'])->put('/admin/products/'.$saved['id'].'/variants', catalogVariantMatrix($saved['id'], 1, $image))
        ->assertRedirect('/admin/products/'.$saved['id'])
        ->assertSessionHasErrors();
    foreach (['content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()))
            ->putJson('/admin/products/'.$saved['id'].'/variants', catalogVariantMatrix($saved['id'], 2, $image))->assertForbidden();
    }
});

it('CatalogVariant HTTP rejects forged saves and does not flash nested variant payloads', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $saved = app(SaveProduct::class)->save($owner, catalogProductInput($image));
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->app->instance('env', 'local');
    $this->putJson('/admin/products/'.$saved['id'].'/variants', catalogVariantMatrix($saved['id'], $saved['version'], $image))->assertStatus(419);
    $this->app->instance('env', 'testing');
    $this->from('/admin/products/'.$saved['id'])->put('/admin/products/'.$saved['id'].'/variants', [
        'version' => $saved['version'],
        'options' => [['name' => 'Color', 'values' => ['Pine']]],
        'variants' => [],
    ])->assertRedirect('/admin/products/'.$saved['id'])->assertSessionHasErrors()->assertSessionMissing('_old_input.variants');
});
