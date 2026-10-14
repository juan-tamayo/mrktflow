<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\ApplySchemaMigration;
use App\Domain\Catalog\Actions\PreviewSchemaMigration;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveProductSchema;
use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\StaffPermissions;
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

/** @return array<string, mixed> */
function catalogMigrationInput(array $saved, array $overrides = []): array
{
    $attributes = $overrides['attributes'] ?? array_merge(catalogSchemaInput()['attributes'], [[
        'key' => 'body.weight', 'type' => 'integer', 'unit' => 'g', 'required' => false,
        'comparable' => true, 'filterable' => true, 'label' => 'Weight',
    ]]);
    unset($overrides['attributes']);

    return catalogSchemaInput(array_replace([
        'id' => $saved['id'],
        'version' => $saved['version'],
        'attributes' => $attributes,
    ], $overrides));
}

it('CatalogSchemaMigration previews affected products and invalid values for a new version', function (): void {
    $owner = catalogInstalledOwner();
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    $image = catalogReadyImage($owner->id);
    app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published',
        'schema_id' => $schema['id'],
        'attributes' => [
            'sensor.megapixels' => '24.2',
            'lens.mount' => 'rf',
            'body.stabilized' => true,
        ],
    ]));
    $preview = app(PreviewSchemaMigration::class)->preview($owner, catalogMigrationInput($schema, [
        'attributes' => array_merge(catalogSchemaInput()['attributes'], [[
            'key' => 'body.weight', 'type' => 'integer', 'unit' => 'g', 'required' => true,
            'comparable' => true, 'filterable' => true, 'label' => 'Weight',
        ]]),
    ]));
    expect($preview['affected_products'])->toBe(1);
    expect($preview['compatible'])->toBeTrue();
    expect($preview['can_apply'])->toBeFalse();
    expect($preview['invalid_values'])->toHaveCount(1);
    expect($preview['invalid_values'][0]['field'])->toBe('body.weight');
    expect($preview['comparison_changes'][0]['key'])->toBe('body.weight');
    expect($preview['comparison_changes'][0]['change'])->toBe('added');
    expect($preview['comparison_changes'][0]['comparable'])->toBeTrue();
});

it('CatalogSchemaMigration migrates an additive optional field and keeps prior values', function (): void {
    $owner = catalogInstalledOwner();
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    $product = app(SaveProduct::class)->save($owner, catalogProductInput(catalogReadyImage($owner->id), [
        'status' => 'published',
        'schema_id' => $schema['id'],
        'attributes' => [
            'sensor.megapixels' => '24.2',
            'lens.mount' => 'rf',
        ],
    ]));
    $priorVersionId = (string) DB::table('products')->where('id', $product['id'])->value('schema_version_id');
    $preview = app(PreviewSchemaMigration::class)->preview($owner, catalogMigrationInput($schema));
    expect($preview['affected_products'])->toBe(1);
    expect($preview['invalid_values'])->toBe([]);
    expect($preview['can_apply'])->toBeTrue();
    $applied = app(ApplySchemaMigration::class)->apply($owner, catalogMigrationInput($schema));
    expect($applied['schema_version'])->toBe(2);
    expect((int) DB::table('product_schema_versions')->where('product_schema_id', $schema['id'])->count())->toBe(2);
    expect((string) DB::table('products')->where('id', $product['id'])->value('schema_version_id'))->not->toBe($priorVersionId);
    $public = app(CatalogReader::class)->publicProduct($product['id']);
    expect($public['schema']['key'])->toBe('camera');
    $labels = array_column($public['typed_attributes'], 'label');
    expect($labels)->toContain('Megapixels');
    expect($labels)->not->toContain('Weight');
    expect(app(CatalogReader::class)->staffProduct($product['id'])['attributes']['sensor.megapixels'])->toBe('24.2');
    $this->get('/products/studio-lamp')->assertOk()->assertSee('24.2');
    $this->get('/')->assertStatus(503)->assertDontSee('Studio Lamp');
});

it('CatalogSchemaMigration rejects an incompatible type change and leaves the prior version intact', function (): void {
    $owner = catalogInstalledOwner();
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    $product = app(SaveProduct::class)->save($owner, catalogProductInput(catalogReadyImage($owner->id), [
        'schema_id' => $schema['id'],
        'attributes' => ['sensor.megapixels' => '24.2', 'lens.mount' => 'rf'],
    ]));
    $prior = [
        'versions' => (int) DB::table('product_schema_versions')->count(),
        'values' => (int) DB::table('attribute_values')->count(),
        'schema_version_id' => (string) DB::table('products')->where('id', $product['id'])->value('schema_version_id'),
        'current_version_id' => (string) DB::table('product_schemas')->where('id', $schema['id'])->value('current_version_id'),
        'definition_type' => (string) DB::table('attribute_definitions')->where('semantic_key', 'sensor.megapixels')->value('type'),
    ];
    $incompatible = catalogMigrationInput($schema, [
        'attributes' => [[
            'key' => 'sensor.megapixels', 'type' => 'integer', 'unit' => 'MP', 'required' => true,
            'comparable' => true, 'filterable' => true, 'label' => 'Megapixels',
        ]],
    ]);
    $preview = app(PreviewSchemaMigration::class)->preview($owner, $incompatible);
    expect($preview['compatible'])->toBeFalse();
    expect($preview['can_apply'])->toBeFalse();
    expect($preview['incompatible_fields'][0]['key'])->toBe('sensor.megapixels');
    expect(fn () => app(ApplySchemaMigration::class)->apply($owner, $incompatible))->toThrow(ValidationException::class);
    expect((int) DB::table('product_schema_versions')->count())->toBe($prior['versions']);
    expect((int) DB::table('attribute_values')->count())->toBe($prior['values']);
    expect((string) DB::table('products')->where('id', $product['id'])->value('schema_version_id'))->toBe($prior['schema_version_id']);
    expect((string) DB::table('product_schemas')->where('id', $schema['id'])->value('current_version_id'))->toBe($prior['current_version_id']);
    expect((string) DB::table('attribute_definitions')->where('semantic_key', 'sensor.megapixels')->value('type'))->toBe($prior['definition_type']);
});

it('CatalogSchemaMigration refuses a required-field conversion that would invalidate products', function (): void {
    $owner = catalogInstalledOwner();
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    app(SaveProduct::class)->save($owner, catalogProductInput(catalogReadyImage($owner->id), [
        'schema_id' => $schema['id'],
        'attributes' => ['sensor.megapixels' => '24.2', 'lens.mount' => 'rf'],
    ]));
    $required = catalogMigrationInput($schema, [
        'attributes' => array_merge(catalogSchemaInput()['attributes'], [[
            'key' => 'body.weight', 'type' => 'integer', 'unit' => 'g', 'required' => true,
            'comparable' => true, 'filterable' => true, 'label' => 'Weight',
        ]]),
    ]);
    expect(fn () => app(ApplySchemaMigration::class)->apply($owner, $required))->toThrow(ValidationException::class);
    expect((int) DB::table('product_schema_versions')->where('product_schema_id', $schema['id'])->count())->toBe(1);
});

it('CatalogSchemaMigration HTTP keeps privileged staff behind two-factor before migration', function (): void {
    $owner = catalogInstalledOwner();
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->postJson('/admin/schemas/'.$schema['id'].'/migration/preview', ['schema' => catalogMigrationInput($schema)])->assertForbidden();
    $this->postJson('/admin/schemas/'.$schema['id'].'/migration', ['schema' => catalogMigrationInput($schema)])->assertForbidden();
    $this->get('/admin/schemas/'.$schema['id'])->assertRedirect('/admin/two-factor');
});

it('CatalogSchemaMigration HTTP previews and applies an additive field for authorized staff', function (): void {
    $owner = catalogInstalledOwner();
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    app(SaveProduct::class)->save($owner, catalogProductInput(catalogReadyImage($owner->id), [
        'schema_id' => $schema['id'],
        'attributes' => ['sensor.megapixels' => '24.2', 'lens.mount' => 'rf'],
    ]));
    $this->post('/admin/schemas/'.$schema['id'].'/migration/preview', ['schema' => catalogMigrationInput($schema)])->assertRedirect('/admin/login');
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/schemas/'.$schema['id'])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ProductSchema')
        ->where('schema.assigned_product_count', 1)
        ->where('schema.schema_version', 1));
    $this->from('/admin/schemas/'.$schema['id'])
        ->post('/admin/schemas/'.$schema['id'].'/migration/preview', ['schema' => catalogMigrationInput($schema)])
        ->assertRedirect('/admin/schemas/'.$schema['id']);
    $this->get('/admin/schemas/'.$schema['id'])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ProductSchema')
        ->where('migration.affected_products', 1)
        ->where('migration.can_apply', true));
    $this->from('/admin/schemas/'.$schema['id'])
        ->post('/admin/schemas/'.$schema['id'].'/migration', ['schema' => catalogMigrationInput($schema)])
        ->assertRedirect('/admin/schemas/'.$schema['id']);
    expect((int) DB::table('product_schema_versions')->where('product_schema_id', $schema['id'])->max('version'))->toBe(2);
    $this->get('/')->assertStatus(503);
});

it('CatalogSchemaMigration HTTP denies guests customers and staff without catalog write', function (): void {
    $owner = catalogInstalledOwner();
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    $payload = ['schema' => catalogMigrationInput($schema)];
    $this->postJson('/admin/schemas/'.$schema['id'].'/migration/preview', $payload)->assertUnauthorized();
    $this->postJson('/admin/schemas/'.$schema['id'].'/migration', $payload)->assertUnauthorized();
    $customer = new CustomerAccount;
    $customer->forceFill(['name' => 'Shopper', 'email' => 'shopper@example.test', 'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1])->save();
    $this->actingAs($customer, 'customer')->postJson('/admin/schemas/'.$schema['id'].'/migration', $payload)->assertUnauthorized();
    foreach (['content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $this->actingAs($owner->fresh(), 'staff')->withSession(catalogStaffSession($owner->fresh()))
            ->postJson('/admin/schemas/'.$schema['id'].'/migration/preview', $payload)->assertForbidden();
        $this->postJson('/admin/schemas/'.$schema['id'].'/migration', $payload)->assertForbidden();
    }
});

it('CatalogSchemaMigration HTTP rejects forged migration posts', function (): void {
    $owner = catalogInstalledOwner();
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->app->instance('env', 'local');
    $this->postJson('/admin/schemas/'.$schema['id'].'/migration/preview', ['schema' => catalogMigrationInput($schema)])->assertStatus(419);
    $this->postJson('/admin/schemas/'.$schema['id'].'/migration', ['schema' => catalogMigrationInput($schema)])->assertStatus(419);
});
