<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveProductSchema;
use App\Domain\Catalog\Contracts\CatalogReader;
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

it('CatalogSchema creates a versioned schema with typed fields enum choices and comparison semantics', function (): void {
    $owner = catalogInstalledOwner();
    $saved = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    expect($saved['key'])->toBe('camera');
    expect($saved['version'])->toBe(1);
    expect($saved['schema_version'])->toBe(1);
    expect($saved['comparison_group'])->toBe('cameras');
    $staff = app(CatalogReader::class)->staffSchema($saved['id']);
    expect($staff['attributes'])->toHaveCount(3);
    expect($staff['attributes'][0]['key'])->toBe('sensor.megapixels');
    expect($staff['attributes'][0]['type'])->toBe('decimal');
    expect($staff['attributes'][1]['options'])->toBe(['rf', 'e', 'z']);
    expect($staff['attributes'][2]['comparable'])->toBeTrue();
    expect(DB::table('product_schema_versions')->where('product_schema_id', $saved['id'])->count())->toBe(1);
});

it('CatalogSchema validates product values against the assigned schema', function (): void {
    $owner = catalogInstalledOwner();
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    $image = catalogReadyImage($owner->id);
    $valid = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published',
        'schema_id' => $schema['id'],
        'attributes' => [
            'sensor.megapixels' => '24.2',
            'lens.mount' => 'rf',
            'body.stabilized' => true,
        ],
    ]));
    $public = app(CatalogReader::class)->publicProduct($valid['id']);
    expect($public['schema']['key'])->toBe('camera');
    expect($public['schema']['comparison_group'])->toBe('cameras');
    $labels = array_column($public['typed_attributes'], 'label');
    expect($labels)->toContain('Megapixels');
    expect($labels)->toContain('Lens mount');
    expect(json_encode($public))->not->toContain('original_key', $owner->password);
    $page = $this->get('/products/studio-lamp');
    $page->assertOk()->assertSee('24.2')->assertSee('rf')->assertSee('Megapixels')->assertDontSee('Add to cart');
    $this->get('/')->assertStatus(503)->assertDontSee('Studio Lamp');
});

it('CatalogSchema rejects invalid numeric enum and required values', function (): void {
    $owner = catalogInstalledOwner();
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    $image = catalogReadyImage($owner->id);
    expect(fn () => app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'slug' => 'bad-numeric', 'sku' => 'CAM-NUM', 'schema_id' => $schema['id'],
        'attributes' => ['sensor.megapixels' => 'not-a-number', 'lens.mount' => 'rf'],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'slug' => 'bad-enum', 'sku' => 'CAM-ENUM', 'schema_id' => $schema['id'],
        'attributes' => ['sensor.megapixels' => '24.2', 'lens.mount' => 'ef'],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'slug' => 'missing-required', 'sku' => 'CAM-REQ', 'schema_id' => $schema['id'],
        'attributes' => ['lens.mount' => 'rf'],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'slug' => 'unknown-field', 'sku' => 'CAM-XTRA', 'schema_id' => $schema['id'],
        'attributes' => ['sensor.megapixels' => '24.2', 'lens.mount' => 'rf', 'price.secret' => '1'],
    ])))->toThrow(ValidationException::class);
    expect(DB::table('products')->count())->toBe(0);
});

it('CatalogSchema refuses to change a semantic attribute type under the same key', function (): void {
    $owner = catalogInstalledOwner();
    app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    expect(fn () => app(SaveProductSchema::class)->save($owner, catalogSchemaInput([
        'key' => 'camera-pro',
        'label' => 'Pro camera',
        'attributes' => [[
            'key' => 'sensor.megapixels', 'type' => 'integer', 'unit' => 'MP', 'required' => true,
            'comparable' => true, 'filterable' => true, 'label' => 'Megapixels',
        ]],
    ])))->toThrow(ValidationException::class);
});

it('CatalogSchema HTTP keeps privileged staff behind two-factor before schema admin', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/schemas')->assertRedirect('/admin/two-factor');
    $this->get('/admin/schemas/create')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/schemas', ['schema' => catalogSchemaInput()])->assertForbidden();
});

it('CatalogSchema HTTP lets authorized staff create a schema and assign it on a product', function (): void {
    $owner = catalogInstalledOwner();
    $this->get('/admin/schemas')->assertRedirect('/admin/login');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/schemas/create')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('ProductSchema'));
    $this->post('/admin/schemas', ['schema' => catalogSchemaInput()])->assertRedirect();
    $schemaId = DB::table('product_schemas')->value('id');
    expect(is_string($schemaId))->toBeTrue();
    expect((int) DB::table('product_schema_versions')->value('version'))->toBe(1);
    $this->get('/admin/products/create')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ProductEdit')->where('schemas.0.key', 'camera'));
    $this->post('/admin/products', [
        'product' => [
            'title' => 'Studio Camera', 'slug' => 'studio-camera', 'description' => 'A mirrorless body.',
            'status' => 'published', 'sku' => 'CAM-01', 'price_amount' => 129900, 'image_alt' => 'Camera body',
            'schema_id' => $schemaId,
            'attributes' => ['sensor.megapixels' => '32.5', 'lens.mount' => 'e', 'body.stabilized' => '1'],
        ],
        'image' => UploadedFile::fake()->image('camera.png', 40, 40),
    ])->assertRedirect();
    expect(app(CatalogReader::class)->publicProduct('studio-camera')['typed_attributes'][0]['value'])->toBe('32.5');
    $this->get('/admin/schemas')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ProductSchemaIndex')->where('schemas.0.label', 'Camera'));
});

it('CatalogSchema HTTP denies guests customers and staff without catalog write', function (): void {
    $owner = catalogInstalledOwner();
    $this->postJson('/admin/schemas', ['schema' => catalogSchemaInput()])->assertUnauthorized();
    $customer = new CustomerAccount;
    $customer->forceFill(['name' => 'Shopper', 'email' => 'shopper@example.test', 'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1])->save();
    $this->actingAs($customer, 'customer')->postJson('/admin/schemas', ['schema' => catalogSchemaInput()])->assertUnauthorized();
    foreach (['content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()))
            ->postJson('/admin/schemas', ['schema' => catalogSchemaInput()])->assertForbidden();
        $this->getJson('/admin/schemas/create')->assertForbidden();
    }
});

it('CatalogSchema updates unused fields and rejects field changes after products are assigned', function (): void {
    $owner = catalogInstalledOwner();
    $saved = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    $updated = app(SaveProductSchema::class)->save($owner, catalogSchemaInput([
        'id' => $saved['id'],
        'version' => $saved['version'],
        'label' => 'Camera body',
        'comparison_group' => 'mirrorless',
        'attributes' => [[
            'key' => 'body.color', 'type' => 'string', 'required' => true, 'comparable' => false,
            'filterable' => true, 'label' => 'Color',
        ]],
    ]));
    expect($updated['version'])->toBe(2);
    expect($updated['schema_version'])->toBe(1);
    expect(app(CatalogReader::class)->staffSchema($saved['id'])['attributes'][0]['key'])->toBe('body.color');
    $image = catalogReadyImage($owner->id);
    app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'schema_id' => $saved['id'],
        'attributes' => ['body.color' => 'Pine'],
    ]));
    expect(fn () => app(SaveProductSchema::class)->save($owner, catalogSchemaInput([
        'id' => $saved['id'],
        'version' => $updated['version'],
        'attributes' => [[
            'key' => 'body.weight', 'type' => 'integer', 'unit' => 'g', 'required' => true,
            'comparable' => true, 'filterable' => true, 'label' => 'Weight',
        ]],
    ])))->toThrow(ValidationException::class);
    $relabeled = app(SaveProductSchema::class)->save($owner, catalogSchemaInput([
        'id' => $saved['id'],
        'version' => $updated['version'],
        'label' => 'Assigned camera',
        'comparison_group' => 'bodies',
        'attributes' => [[
            'key' => 'body.color', 'type' => 'string', 'required' => true, 'comparable' => false,
            'filterable' => true, 'label' => 'Color',
        ]],
    ]));
    expect($relabeled['comparison_group'])->toBe('bodies');
});

it('CatalogSchema stores string integer date and false boolean values and can clear a schema', function (): void {
    $owner = catalogInstalledOwner();
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput([
        'key' => 'notebook',
        'label' => 'Notebook',
        'comparison_group' => 'stationery',
        'attributes' => [
            ['key' => 'cover.material', 'type' => 'string', 'required' => true, 'comparable' => true, 'filterable' => true, 'label' => 'Material'],
            ['key' => 'page.count', 'type' => 'integer', 'unit' => 'pp', 'required' => true, 'comparable' => true, 'filterable' => true, 'label' => 'Pages'],
            ['key' => 'published.on', 'type' => 'date', 'required' => false, 'comparable' => false, 'filterable' => true, 'label' => 'Published'],
            ['key' => 'cover.lined', 'type' => 'boolean', 'required' => false, 'comparable' => true, 'filterable' => true, 'label' => 'Lined'],
        ],
    ]));
    $image = catalogReadyImage($owner->id);
    $saved = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published',
        'schema_id' => $schema['id'],
        'attributes' => [
            'cover.material' => 'cloth',
            'page.count' => 192,
            'published.on' => '2026-03-01',
            'cover.lined' => false,
        ],
    ]));
    $staff = app(CatalogReader::class)->staffProduct($saved['id']);
    expect($staff['schema_id'])->toBe($schema['id']);
    expect($staff['attributes']['cover.material'])->toBe('cloth');
    expect($staff['attributes']['page.count'])->toBe('192');
    expect($staff['attributes']['cover.lined'])->toBeFalse();
    $public = app(CatalogReader::class)->publicProduct($saved['id']);
    expect(array_column($public['typed_attributes'], 'value'))->toContain('cloth', '192', '2026-03-01', 'No');
    $cleared = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'id' => $saved['id'], 'version' => $saved['version'], 'status' => 'published',
        'schema_id' => null, 'attributes' => [],
    ]));
    expect(app(CatalogReader::class)->staffProduct($cleared['id'])['schema_id'])->toBeNull();
    expect(app(CatalogReader::class)->publicProduct($cleared['id'])['typed_attributes'])->toBe([]);
    expect(fn () => app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'slug' => 'bad-int', 'sku' => 'NB-INT', 'schema_id' => $schema['id'],
        'attributes' => ['cover.material' => 'cloth', 'page.count' => '12.5'],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'slug' => 'bad-date', 'sku' => 'NB-DATE', 'schema_id' => $schema['id'],
        'attributes' => ['cover.material' => 'cloth', 'page.count' => '10', 'published.on' => 'March 1'],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'slug' => 'bad-bool', 'sku' => 'NB-BOOL', 'schema_id' => $schema['id'],
        'attributes' => ['cover.material' => 'cloth', 'page.count' => '10', 'cover.lined' => 'maybe'],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'slug' => 'bad-text', 'sku' => 'NB-TEXT', 'schema_id' => $schema['id'],
        'attributes' => ['cover.material' => "cloth\x00", 'page.count' => '10'],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'slug' => 'bad-float', 'sku' => 'NB-FLOAT', 'schema_id' => $schema['id'],
        'attributes' => ['cover.material' => 'cloth', 'page.count' => '10', 'sensor.skip' => 1],
    ])))->toThrow(ValidationException::class);
});

it('CatalogSchema rejects duplicate keys units on text fields and unknown schemas', function (): void {
    $owner = catalogInstalledOwner();
    app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    expect(fn () => app(SaveProductSchema::class)->save($owner, catalogSchemaInput(['label' => 'Duplicate'])))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveProductSchema::class)->save($owner, catalogSchemaInput([
        'key' => 'bag',
        'label' => 'Bag',
        'comparison_group' => 'bags',
        'attributes' => [[
            'key' => 'bag.color', 'type' => 'string', 'unit' => 'cm', 'required' => true,
            'comparable' => true, 'filterable' => true, 'label' => 'Color',
        ]],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveProduct::class)->save($owner, catalogProductInput(catalogReadyImage($owner->id), [
        'schema_id' => '11111111-1111-4111-8111-111111111111',
        'attributes' => [],
    ])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveProduct::class)->save($owner, catalogProductInput(catalogReadyImage($owner->id), [
        'slug' => 'float-price', 'sku' => 'CAM-FLT', 'schema_id' => DB::table('product_schemas')->value('id'),
        'attributes' => ['sensor.megapixels' => 24.2, 'lens.mount' => 'rf'],
    ])))->toThrow(ValidationException::class);
});

it('CatalogSchema HTTP updates a schema and keeps privileged 2FA', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->post('/admin/schemas', ['schema' => catalogSchemaInput()])->assertRedirect();
    $id = (string) DB::table('product_schemas')->value('id');
    $version = (int) DB::table('product_schemas')->value('version');
    $this->from('/admin/schemas/'.$id)->patch('/admin/schemas/'.$id, ['schema' => catalogSchemaInput([
        'label' => 'Field camera', 'version' => $version,
    ])])->assertRedirect('/admin/schemas/'.$id);
    expect(DB::table('product_schemas')->value('label'))->toBe('Field camera');
    $this->from('/admin/schemas/'.$id)->patch('/admin/schemas/'.$id, ['schema' => catalogSchemaInput([
        'label' => 'Stale', 'version' => $version,
    ])])->assertRedirect('/admin/schemas/'.$id)->assertSessionHasErrors();
    $this->get('/admin/schemas/'.$id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ProductSchema')->where('schema.label', 'Field camera'));
    $this->get('/')->assertStatus(503);
});

it('CatalogSchema HTTP rejects forged saves and does not flash nested schema payloads', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->app->instance('env', 'local');
    $this->postJson('/admin/schemas', ['schema' => catalogSchemaInput()])->assertStatus(419);
    $this->app->instance('env', 'testing');
    $this->from('/admin/schemas/create')->post('/admin/schemas', ['schema' => [
        'key' => '', 'label' => '', 'comparison_group' => '', 'default_template_key' => 'product.default', 'attributes' => [],
    ]])->assertRedirect('/admin/schemas/create')->assertSessionHasErrors()->assertSessionMissing('_old_input.schema');
});
