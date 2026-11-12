<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveProductSchema;
use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\CheckoutOrders\Actions\CreateQuote;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Storefront\Actions\ComposePage;
use App\Domain\ThemesContent\Actions\AssignLayout;
use App\Domain\ThemesContent\Actions\SaveContent;
use App\Domain\ThemesContent\Support\VisualTemplates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

/** @param  list<string>|null  $permissions */
function t066StaffAs(StaffAccount $staff, string $role, ?array $permissions = null): StaffAccount
{
    DB::table('staff_accounts')->where('id', $staff->id)->update([
        'role' => $role,
        'permissions' => json_encode($permissions ?? StaffPermissions::forRole($role)),
    ]);

    return $staff->fresh();
}

/**
 * @return array{owner: StaffAccount, schema: array{id: string, version: int, schema_version: int, comparison_group: string, key: string}, product: array{id: string, slug: string, version: int, status: string, type: string}, category: array{id: string, path: string, version: int}, variant: string, attributes: array<string, mixed>}
 */
function t066Catalog(): array
{
    $owner = catalogInstalledOwner();
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    $category = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $attributes = [
        'sensor.megapixels' => '24.2',
        'lens.mount' => 'rf',
        'body.stabilized' => true,
    ];
    $product = app(SaveProduct::class)->save($owner, catalogProductInput(catalogReadyImage($owner->id), [
        'status' => 'published',
        'schema_id' => $schema['id'],
        'category_ids' => [$category['id']],
        'attributes' => $attributes,
        'price_amount' => 2499,
    ]));

    return [
        'owner' => $owner,
        'schema' => $schema,
        'product' => $product,
        'category' => $category,
        'variant' => (string) DB::table('product_variants')->where('product_id', $product['id'])->value('id'),
        'attributes' => $attributes,
    ];
}

it('ThemesContentAssignment assigns a product layout independently of the schema and keeps attributes and quote totals', function (): void {
    $catalog = t066Catalog();
    shippingSavedRules($catalog['owner'], [
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['amount' => 300])]])],
    ]);
    pricingSavedTaxRules($catalog['owner']);

    $guest = CartOwner::guest('guest-layout-token');
    $cart = app(UpdateCart::class)->replaceLine($guest, [
        'variant_id' => $catalog['variant'], 'quantity' => 1, 'expected_revision' => 1,
    ]);
    $beforeQuote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision']));
    $beforeAttributes = app(CatalogReader::class)->publicProduct($catalog['product']['id'])['typed_attributes'];
    $schemaDefault = (string) DB::table('product_schema_versions')
        ->where('product_schema_id', $catalog['schema']['id'])
        ->value('default_template_key');
    expect($schemaDefault)->toBe('product.default');

    $this->get('/products/studio-lamp')->assertOk()
        ->assertSee('data-visual-template="product.default"', false)
        ->assertSee('24.2')
        ->assertSee('Megapixels');

    $assigned = app(AssignLayout::class)->assign($catalog['owner'], [
        'subject_type' => 'product',
        'subject_id' => $catalog['product']['id'],
        'template_key' => 'product.compact',
    ]);
    expect($assigned['template_key'])->toBe('product.compact')
        ->and($assigned['subject_type'])->toBe('product');

    expect((string) DB::table('product_schema_versions')
        ->where('product_schema_id', $catalog['schema']['id'])
        ->value('default_template_key'))->toBe('product.default');
    expect((int) DB::table('product_variants')->where('id', $catalog['variant'])->value('price_amount'))->toBe(2499);
    expect(app(CatalogReader::class)->publicProduct($catalog['product']['id'])['typed_attributes'])->toBe($beforeAttributes);
    expect(Schema::hasColumn('layout_assignments', 'price_amount'))->toBeFalse();

    $afterQuote = app(CreateQuote::class)->create($guest, checkoutQuoteInput($cart['revision']));
    expect($afterQuote['grand_total'])->toBe($beforeQuote['grand_total'])
        ->and($afterQuote['merchandise'])->toBe($beforeQuote['merchandise']);

    $this->get('/products/studio-lamp')->assertOk()
        ->assertSee('data-visual-template="product.compact"', false)
        ->assertDontSee('data-visual-template="product.default"', false)
        ->assertSee('24.2')
        ->assertSee('Megapixels')
        ->assertSee('Add to cart');
    $this->get('/')->assertStatus(503)->assertSee('This store is getting ready');
});

it('ThemesContentAssignment category landing binds live products without copying prices', function (): void {
    $catalog = t066Catalog();
    $this->get('/categories/lighting')->assertOk()
        ->assertSee('data-visual-template="category.default"', false)
        ->assertSee('USD 24.99');

    app(AssignLayout::class)->assign($catalog['owner'], [
        'subject_type' => 'category',
        'subject_id' => $catalog['category']['id'],
        'template_key' => 'category.landing',
    ]);
    $row = DB::table('layout_assignments')->where('subject_id', $catalog['category']['id'])->first();
    expect($row)->not->toBeNull()
        ->and((string) $row->template_key)->toBe('category.landing')
        ->and(json_encode($row))->not->toContain('2499', '24.99');

    $this->get('/categories/lighting')->assertOk()
        ->assertSee('data-visual-template="category.landing"', false)
        ->assertSee('USD 24.99')
        ->assertSee('Studio Lamp')
        ->assertSee('/products/studio-lamp');

    app(SaveProduct::class)->save($catalog['owner'], catalogProductInput(
        (string) DB::table('product_media')->where('product_id', $catalog['product']['id'])->value('media_asset_id'),
        [
            'id' => $catalog['product']['id'],
            'version' => $catalog['product']['version'],
            'status' => 'published',
            'schema_id' => $catalog['schema']['id'],
            'category_ids' => [$catalog['category']['id']],
            'attributes' => $catalog['attributes'],
            'price_amount' => 3199,
        ],
    ));

    $this->get('/categories/lighting')->assertOk()
        ->assertSee('data-visual-template="category.landing"', false)
        ->assertSee('USD 31.99')
        ->assertDontSee('USD 24.99');
    expect((string) DB::table('layout_assignments')->where('subject_id', $catalog['category']['id'])->value('template_key'))
        ->toBe('category.landing');
});

it('ThemesContentAssignment falls back to the schema default until a product assignment exists', function (): void {
    $catalog = t066Catalog();
    app(SaveProductSchema::class)->save($catalog['owner'], catalogSchemaInput([
        'id' => $catalog['schema']['id'],
        'version' => $catalog['schema']['version'],
        'default_template_key' => 'product.compact',
    ]));
    $this->get('/products/studio-lamp')->assertOk()
        ->assertSee('data-visual-template="product.compact"', false);
    app(AssignLayout::class)->assign($catalog['owner'], [
        'subject_type' => 'product',
        'subject_id' => $catalog['product']['id'],
        'template_key' => 'product.default',
    ]);
    $this->get('/products/studio-lamp')->assertOk()
        ->assertSee('data-visual-template="product.default"', false);
});

it('ThemesContentAssignment refuses unknown subjects, unknown keys and cross-kind templates', function (): void {
    $catalog = t066Catalog();
    $page = app(SaveContent::class)->savePage($catalog['owner'], [
        'title' => 'About the studio',
        'slug' => 'about',
        'status' => 'published',
    ]);
    expect(fn () => app(AssignLayout::class)->assign($catalog['owner'], [
        'subject_type' => 'product',
        'subject_id' => $catalog['product']['id'],
        'template_key' => 'category.landing',
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(AssignLayout::class)->assign($catalog['owner'], [
        'subject_type' => 'category',
        'subject_id' => $catalog['category']['id'],
        'template_key' => 'not-a-template',
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(AssignLayout::class)->assign($catalog['owner'], [
        'subject_type' => 'page',
        'subject_id' => $page['id'],
        'template_key' => 'page.narrow',
    ]))->not->toThrow(ValidationException::class);
    expect(fn () => app(AssignLayout::class)->assign($catalog['owner'], [
        'subject_type' => 'product',
        'subject_id' => '11111111-1111-4111-8111-111111111111',
        'template_key' => 'product.compact',
    ]))->toThrow(ValidationException::class);
    expect(DB::table('layout_assignments')->count())->toBe(1);
});

it('ThemesContentAssignment ComposePage records the assigned content template', function (): void {
    $compose = app(ComposePage::class);
    $default = $compose->compose('product');
    expect($default->template)->toBe('default-theme::product')
        ->and($default->viewFor('content'))->toBe('default-theme::product');
    $compact = $compose->compose('product', [], 'product.compact');
    expect($compact->template)->toBe('default-theme::product-compact')
        ->and($compact->viewFor('content'))->toBe('default-theme::product-compact')
        ->and($compact->viewFor('product.purchase'))->toBe('storefront.slots.product-purchase');
    $landing = $compose->compose('category', [], 'category.landing');
    expect($landing->template)->toBe('default-theme::category-landing')
        ->and($landing->viewFor('content'))->toBe('default-theme::category-landing');
    expect(fn () => $compose->compose('product', [], 'category.landing'))
        ->toThrow(InvalidArgumentException::class);
    expect(VisualTemplates::fallback('product'))->toBe('product.default');
});

it('ThemesContentAssignment denies guests customers and staff without content write', function (): void {
    $catalog = t066Catalog();
    $payload = [
        'subject_type' => 'product',
        'subject_id' => $catalog['product']['id'],
        'template_key' => 'product.compact',
    ];
    $this->put('/admin/layouts', $payload)->assertRedirect('/admin/login');

    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper',
        'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->putJson('/admin/layouts', $payload)->assertUnauthorized();

    $fulfillment = t066StaffAs($catalog['owner'], 'fulfillment');
    $this->actingAs($fulfillment, 'staff')->withSession(catalogStaffSession($fulfillment));
    $this->putJson('/admin/layouts', $payload)->assertForbidden();
    expect(fn () => app(AssignLayout::class)->assign($fulfillment, $payload))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('layout_assignments')->count())->toBe(0);
});

it('ThemesContentAssignment keeps privileged staff behind two-factor', function (): void {
    $catalog = t066Catalog();
    $this->actingAs($catalog['owner'], 'staff')
        ->withSession(['staff_session_version' => $catalog['owner']->session_version]);
    $this->put('/admin/layouts', [
        'subject_type' => 'product',
        'subject_id' => $catalog['product']['id'],
        'template_key' => 'product.compact',
    ])->assertRedirect('/admin/two-factor');
    expect(DB::table('layout_assignments')->count())->toBe(0);
});

it('ThemesContentAssignment HTTP lets authorized staff assign layouts from product category and page editors', function (): void {
    $catalog = t066Catalog();
    $page = app(SaveContent::class)->savePage($catalog['owner'], [
        'title' => 'About the studio',
        'slug' => 'about',
        'status' => 'published',
    ]);
    $editor = t066StaffAs($catalog['owner'], 'content_editor');
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor));

    $this->from('/admin/products/'.$catalog['product']['id'])->put('/admin/layouts', [
        'subject_type' => 'product',
        'subject_id' => $catalog['product']['id'],
        'template_key' => 'product.compact',
    ])->assertRedirect('/admin/products/'.$catalog['product']['id']);
    $this->from('/admin/categories/'.$catalog['category']['id'])->put('/admin/layouts', [
        'subject_type' => 'category',
        'subject_id' => $catalog['category']['id'],
        'template_key' => 'category.landing',
    ])->assertRedirect('/admin/categories/'.$catalog['category']['id']);
    $this->from('/admin/content')->put('/admin/layouts', [
        'subject_type' => 'page',
        'subject_id' => $page['id'],
        'template_key' => 'page.narrow',
    ])->assertRedirect('/admin/content');

    $this->get('/admin/content')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ContentNavigation')
        ->where('pages.0.template_key', 'page.narrow')
        ->where('layoutOptions.0.key', 'page.default'));

    expect(DB::table('layout_assignments')->count())->toBe(3);
    $this->get('/')->assertStatus(503);
});
