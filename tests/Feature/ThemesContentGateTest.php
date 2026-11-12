<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\ThemesContent\Actions\RegisterTheme;
use App\Domain\ThemesContent\Contracts\ThemeRegistry;
use App\Domain\ThemesContent\Data\ProductView;
use App\Domain\ThemesContent\Support\DeveloperThemeTrust;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

afterEach(function (): void {
    foreach ($GLOBALS['t072Zips'] ?? [] as $path) {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
    $GLOBALS['t072Zips'] = [];
});

/** @return array<string, mixed> */
function t072Hero(string $heading): array
{
    return ['id' => 'intro', 'type' => 'hero', 'version' => 1, 'settings' => ['heading' => $heading]];
}

/**
 * @param  array<string, mixed>  $manifest
 * @param  array<string, string>  $files
 */
function t072Package(array $manifest, array $files = []): UploadedFile
{
    $path = sys_get_temp_dir().'/mrktflow-t072-'.Str::uuid().'.zip';
    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
    $GLOBALS['t072Zips'][] = $path;

    return new UploadedFile($path, 'template.zip', 'application/zip', null, true);
}

/**
 * T072 integration gate (THEME-01..THEME-06). The slices beneath this
 * file each proved their own surface; these journeys prove they compose
 * in one continuous merchant flow over the real HTTP controllers.
 */
it('ThemesContentGate walks import edit preview publish and rollback as one continuous journey', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/')->assertStatus(503)->assertSee('This store is getting ready');

    $this->post('/admin/content/pages', [
        'page' => ['title' => 'About the studio', 'slug' => 'about', 'status' => 'published'],
    ])->assertRedirect('/admin/content');
    $pageId = (string) DB::table('pages')->value('id');
    $this->get('/pages/about')->assertOk()->assertSee('About the studio');

    $staged = $this->post('/admin/content/import', ['file' => t072Package([
        'schemaVersion' => '1.0',
        'kind' => 'template-package',
        'documents' => [[
            'kind' => 'section_draft',
            'page' => 'search',
            'slot' => 'content',
            'sections' => [t072Hero('Imported heading')],
        ]],
        'media' => [],
        'references' => ['products' => [], 'categories' => []],
    ])]);
    $staged->assertRedirect();
    $importId = (string) DB::table('content_import_files')->value('id');
    expect($importId)->not->toBe('');
    $this->post('/admin/content/import/'.$importId.'/apply')->assertRedirect('/admin/content/import/'.$importId);

    $draft = $this->getJson('/admin/content-editor/draft?page=search&slot=content')->assertOk();
    expect($draft->json('version'))->toBe(1)
        ->and($draft->json('publishedRevision'))->toBe(0)
        ->and($draft->json('sections.0.settings.heading'))->toBe('Imported heading');
    $this->get('/')->assertStatus(503)->assertDontSee('Imported heading');

    $edited = t072Hero('Edited imported heading');
    $this->putJson('/admin/content-editor/draft', [
        'page' => 'search',
        'slot' => 'content',
        'version' => 1,
        'sections' => [$edited],
    ])->assertOk()->assertJsonPath('version', 2);
    $preview = $this->postJson('/admin/content-editor/preview', [
        'page' => 'search',
        'slot' => 'content',
        'sections' => [$edited],
    ])->assertOk();
    expect($preview->json('html'))->toContain('Edited imported heading')
        ->and((int) DB::table('content_drafts')->where('page_key', 'search')->where('slot', 'content')->value('published_revision'))->toBe(0);
    $this->get('/admin/preview/sections?page=search&slot=content')
        ->assertOk()
        ->assertSee('Edited imported heading')
        ->assertSee('Content preview')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get('/')->assertStatus(503)->assertDontSee('Edited imported heading');

    $this->post('/admin/content-editor/publish', [
        'page' => 'search',
        'slot' => 'content',
        'version' => 2,
    ])->assertRedirect('/admin/content-editor');
    expect((int) DB::table('content_drafts')->where('page_key', 'search')->where('slot', 'content')->value('published_revision'))->toBe(2);

    $this->post('/admin/content/pages', [
        'page' => ['id' => $pageId, 'version' => 1, 'title' => 'About lighting', 'slug' => 'about', 'status' => 'draft'],
    ])->assertRedirect('/admin/content');
    $this->get('/admin/preview/pages/'.$pageId)
        ->assertOk()
        ->assertSee('About lighting')
        ->assertDontSee('This store is getting ready')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get('/pages/about')->assertOk()->assertSee('About the studio')->assertDontSee('About lighting');
    $this->post('/admin/content/publish', [
        'kind' => 'page',
        'id' => $pageId,
        'version' => 2,
    ])->assertRedirect();
    $this->get('/pages/about')->assertOk()->assertSee('About lighting');

    $this->post('/admin/content/restore', [
        'kind' => 'page',
        'id' => $pageId,
        'revision' => 1,
        'version' => 2,
    ])->assertRedirect();
    $this->get('/pages/about')->assertOk()->assertSee('About the studio')->assertDontSee('About lighting');
    expect((int) DB::table('pages')->where('id', $pageId)->value('published_revision'))->toBe(3);

    $this->post('/admin/content/restore', [
        'kind' => 'section_draft',
        'page' => 'search',
        'slot' => 'content',
        'revision' => 1,
        'version' => 2,
    ])->assertRedirect();
    expect(DB::table('content_drafts')->where('page_key', 'search')->where('slot', 'content')->value('sections'))
        ->toContain('Imported heading')
        ->and((int) DB::table('content_drafts')->where('page_key', 'search')->where('slot', 'content')->value('published_revision'))->toBe(3);

    $this->get('/sitemap.xml')->assertOk();
    $sitemap = $this->get('/sitemap.xml')->getContent();
    expect($sitemap)->toContain('/pages/about')
        ->not->toContain('/admin/preview')
        ->not->toContain('/cart')
        ->not->toContain('/checkout');
    $this->get('/')->assertStatus(503)
        ->assertDontSee('Imported heading')
        ->assertDontSee('Edited imported heading')
        ->assertDontSee('About lighting');
});

it('ThemesContentGate walks developer-theme custom product and category presentation with physical and digital checkout intact', function (): void {
    $owner = catalogInstalledOwner();
    shippingSavedRules($owner, [
        'zones' => [shippingZoneInput(['methods' => [shippingMethodInput(['amount' => 300])]])],
    ]);
    pricingSavedTaxRules($owner);
    pricingSavedCoupon($owner, [
        'code' => 'FREE100',
        'percent_numerator' => 100,
        'percent_denominator' => 100,
        'max_redemptions' => 25,
        'max_redemptions_per_customer' => null,
    ]);
    $image = catalogReadyImage($owner->id);
    $category = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $physical = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published',
        'price_amount' => 2500,
        'category_ids' => [$category['id']],
    ]));
    $physicalVariant = (string) DB::table('product_variants')->where('product_id', $physical['id'])->value('id');
    app(AdjustStock::class)->adjust($owner, [
        'variant_id' => $physicalVariant,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 4,
        'low_stock_threshold' => 1,
    ]);
    $digital = app(SaveProduct::class)->save($owner, catalogDigitalProductInput($image, [
        'status' => 'published',
        'price_amount' => 500,
        'category_ids' => [$category['id']],
    ]));
    $digitalVariant = (string) DB::table('product_variants')->where('product_id', $digital['id'])->value('id');
    $ready = app(VersionDigitalAsset::class)->version($owner, [
        'product_id' => $digital['id'],
        'label' => 'Lighting guide',
        'file' => catalogPrivateTextFile(),
    ]);
    app(VersionDigitalAsset::class)->attach($owner, [
        'product_id' => $digital['id'],
        'variant_id' => $digitalVariant,
        'version_ids' => [$ready['id']],
    ]);

    $this->artisan('theme:validate', ['directory' => 'themes/example'])
        ->expectsOutputToContain('id: mrktflow/example')
        ->expectsOutputToContain('status: valid')
        ->expectsOutputToContain('trust: '.DeveloperThemeTrust::LEVEL)
        ->expectsOutputToContain('sandbox: '.DeveloperThemeTrust::SANDBOX)
        ->expectsOutputToContain('admin_upload: '.DeveloperThemeTrust::ADMIN_UPLOAD)
        ->assertSuccessful();
    expect(app(ThemeRegistry::class)->find('mrktflow/example')?->status)->toBe('valid')
        ->and(DB::table('theme_activations')->count())->toBe(0);

    $previewProduct = json_decode((string) file_get_contents(base_path('themes/default/preview/product.json')), true, flags: JSON_THROW_ON_ERROR);
    $view = ProductView::from($previewProduct);
    $sheet = view('example-theme::product', [
        'product' => $view,
        'selected' => $view->variants[0],
        'preview' => true,
        'breadcrumbs' => [],
        'selectionState' => 'selected',
        'cartRevision' => 1,
        'cartItemCount' => 0,
        'assignedTemplateKey' => 'product.default',
        'metadata' => null,
    ])->render();
    expect($sheet)->toContain('data-theme="example"', 'Technical sheet', $view->title);

    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    app(RegisterTheme::class)->register(base_path('themes/default'));
    $this->post('/admin/themes/activate', ['theme_id' => 'mrktflow/default'])->assertRedirect('/admin/themes');
    expect(DB::table('theme_activations')->whereNull('replaced_at')->value('theme_id'))->toBe('mrktflow/default');

    $this->from('/admin/products/'.$physical['id'])->put('/admin/layouts', [
        'subject_type' => 'product',
        'subject_id' => $physical['id'],
        'template_key' => 'product.compact',
    ])->assertRedirect('/admin/products/'.$physical['id']);
    $this->from('/admin/categories/'.$category['id'])->put('/admin/layouts', [
        'subject_type' => 'category',
        'subject_id' => $category['id'],
        'template_key' => 'category.landing',
    ])->assertRedirect('/admin/categories/'.$category['id']);
    $this->post('/admin/logout');
    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $this->get('/products/studio-lamp')->assertOk()
        ->assertSee('data-visual-template="product.compact"', false)
        ->assertSee('Add to cart')
        ->assertSee('Studio Lamp');
    $this->get('/categories/lighting')->assertOk()
        ->assertSee('data-visual-template="category.landing"', false)
        ->assertSee('Category landing')
        ->assertSee('Studio Lamp')
        ->assertSee('Studio Lighting Guide');

    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $physicalVariant, 'quantity' => 1, 'expectedRevision' => 1,
    ])->assertOk();
    $cart = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    $this->postJson('/store-api/v1/cart/lines', [
        'variantId' => $digitalVariant, 'quantity' => 1, 'expectedRevision' => $cart['revision'],
    ])->assertOk();
    $cart = $this->getJson('/store-api/v1/cart')->assertOk()->json('data');
    expect($cart['lines'])->toHaveCount(2);
    $quote = $this->postJson('/store-api/v1/checkout-quotes', [
        'expectedRevision' => $cart['revision'],
        'email' => 'gate-shopper@example.test',
        'coupon' => 'FREE100',
        'billing' => checkoutAddress(),
        'shipping' => checkoutAddress(),
    ])->assertOk()->json('data');
    expect($quote['grandTotal']['amount'])->toBe(0);
    $this->postJson('/store-api/v1/checkout-quotes/'.$quote['id'].'/accept', [
        'fingerprint' => $quote['fingerprint'],
        'policyAcknowledged' => true,
    ])->assertOk();
    $session = $this->postJson('/store-api/v1/checkout-sessions', [
        'quoteId' => $quote['id'],
        'fingerprint' => $quote['fingerprint'],
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');
    expect($session['zeroTotal'])->toBeTrue()
        ->and($session['orderStatus'])->toBe('confirmed');
    $this->get('/checkout/orders/'.$session['orderId'])->assertOk()->assertSee('Order confirmed');
    expect(DB::table('order_lines')->where('order_id', $session['orderId'])->where('type', 'physical')->count())->toBe(1)
        ->and(DB::table('order_lines')->where('order_id', $session['orderId'])->where('type', 'digital')->count())->toBe(1)
        ->and(DB::table('products')->count())->toBe(2);

    $this->get('/')->assertStatus(503)->assertSee('This store is getting ready');
});
