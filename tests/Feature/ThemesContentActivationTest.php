<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\ThemesContent\Actions\ActivateTheme;
use App\Domain\ThemesContent\Actions\AssignLayout;
use App\Domain\ThemesContent\Actions\PublishContent;
use App\Domain\ThemesContent\Actions\ReadPublishedContent;
use App\Domain\ThemesContent\Actions\RegisterTheme;
use App\Domain\ThemesContent\Actions\RestoreRevision;
use App\Domain\ThemesContent\Actions\SaveContent;
use App\Domain\ThemesContent\Actions\SaveSectionDraft;
use App\Domain\ThemesContent\Exceptions\ThemeActivationFailed;
use App\Domain\ThemesContent\Support\ContentRevisions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

afterEach(function (): void {
    foreach ($GLOBALS['t068ThemeRoots'] ?? [] as $root) {
        File::deleteDirectory($root);
    }
    $GLOBALS['t068ThemeRoots'] = [];
});

/** @param  list<string>|null  $permissions */
function t068StaffAs(StaffAccount $staff, string $role, ?array $permissions = null): StaffAccount
{
    DB::table('staff_accounts')->where('id', $staff->id)->update([
        'role' => $role,
        'permissions' => json_encode($permissions ?? StaffPermissions::forRole($role)),
    ]);

    return $staff->fresh();
}

/** @param  array<string, mixed>  $overrides */
function t068ArticleInput(array $overrides = []): array
{
    return array_replace([
        'title' => 'Choosing a desk lamp',
        'slug' => 'desk-lamp-guide',
        'excerpt' => 'Start with task lighting at the work surface.',
        'status' => 'draft',
    ], $overrides);
}

/** @return array<string, mixed> */
function t068Hero(array $settings = []): array
{
    return ['id' => 'intro', 'type' => 'hero', 'version' => 1, 'settings' => array_merge(['heading' => 'Find your camera'], $settings)];
}

function t068ThemeRoot(): string
{
    $root = sys_get_temp_dir().'/mrktflow-t068-themes-'.Str::uuid();
    File::ensureDirectoryExists($root);
    $GLOBALS['t068ThemeRoots'][] = $root;

    return $root;
}

/**
 * @param  array<string, mixed>  $manifest
 * @param  list<string>  $files
 */
function t068ThemeDirectory(string $root, string $directory, array $manifest, array $files = []): string
{
    $path = $root.'/'.$directory;
    File::ensureDirectoryExists($path);
    foreach ($files as $file) {
        File::ensureDirectoryExists(dirname($path.'/'.$file));
        File::put($path.'/'.$file, 'fixture');
    }
    File::put($path.'/theme.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

    return $path;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function t068Manifest(array $overrides = []): array
{
    $templates = $overrides['templates'] ?? t068CoverageTemplates();
    $filesFromTemplates = array_values($templates);
    unset($overrides['templates']);
    $manifest = array_merge([
        'schemaVersion' => '1.0',
        'id' => 'acme/coverage',
        'version' => '1.0.0',
        'name' => 'Coverage',
        'requires' => ['core' => '>=1.0.0 <2.0.0', 'themeApi' => '1'],
        'templates' => $templates,
        'sections' => ['hero', 'rich-text', 'featured-product', 'specifications'],
        'assets' => ['entry' => 'src/entry.ts', 'style' => 'src/theme.css'],
    ], $overrides);
    $manifest['templates'] = $templates;

    return [$manifest, array_values(array_unique([...$filesFromTemplates, 'src/entry.ts', 'src/theme.css']))];
}

/** @return array<string, string> */
function t068CoverageTemplates(array $remove = []): array
{
    $templates = [
        'layout' => 'views/layout.blade.php',
        'home' => 'views/home.blade.php',
        'category.default' => 'views/category.blade.php',
        'search' => 'views/search.blade.php',
        'product.default' => 'views/product.blade.php',
        'compare' => 'views/compare.blade.php',
        'wishlist' => 'views/wishlist.blade.php',
        'cart' => 'views/cart.blade.php',
        'checkout' => 'views/checkout.blade.php',
        'payment.status' => 'views/payment-status.blade.php',
        'order.status' => 'views/order-status.blade.php',
        'account.orders' => 'views/account-orders.blade.php',
        'account.order' => 'views/account-order.blade.php',
        'account.downloads' => 'views/account-downloads.blade.php',
        'download.grant' => 'views/download-grant.blade.php',
        'account.preferences' => 'views/preferences.blade.php',
        'page.default' => 'views/page.blade.php',
    ];
    foreach ($remove as $key) {
        unset($templates[$key]);
    }

    return $templates;
}

function t068RegisterCoverageTheme(string $id, array $manifestOverrides = [], array $removeTemplates = []): string
{
    $root = t068ThemeRoot();
    [$manifest, $files] = t068Manifest(array_merge([
        'id' => $id,
        'templates' => t068CoverageTemplates($removeTemplates),
    ], $manifestOverrides));
    $directory = t068ThemeDirectory($root, str_replace('/', '-', $id), $manifest, $files);
    $registration = (new RegisterTheme($root))->register($directory);
    expect($registration->status)->toBe('valid');

    return $registration->themeId;
}

function t068ActivateDefault(StaffAccount $owner): array
{
    app(RegisterTheme::class)->register(base_path('themes/default'));

    return app(ActivateTheme::class)->activate($owner, ['theme_id' => 'mrktflow/default']);
}

it('ThemesContentActivation restores a prior published revision as a new live snapshot', function (): void {
    $owner = catalogInstalledOwner();
    $created = app(SaveContent::class)->saveArticle($owner, t068ArticleInput(['status' => 'published']));
    $draft = app(SaveContent::class)->saveArticle($owner, t068ArticleInput([
        'id' => $created['id'],
        'version' => 1,
        'title' => 'Choosing a floor lamp',
        'excerpt' => 'A later unpublished rewrite.',
        'status' => 'draft',
    ]));
    app(PublishContent::class)->publish($owner, ['kind' => 'article', 'id' => $created['id'], 'version' => $draft['version']]);
    expect(app(ReadPublishedContent::class)->article($created['id'])['title'])->toBe('Choosing a floor lamp');

    $restored = app(RestoreRevision::class)->restore($owner, [
        'kind' => 'article',
        'id' => $created['id'],
        'revision' => 1,
        'version' => 2,
    ]);
    expect($restored['published_revision'])->toBe(3)
        ->and($restored['version'])->toBe(3)
        ->and($restored['restored_revision'])->toBe(1);
    $live = app(ReadPublishedContent::class)->article($created['id']);
    expect($live['revision'])->toBe(3)
        ->and($live['title'])->toBe('Choosing a desk lamp')
        ->and($live['excerpt'])->toBe('Start with task lighting at the work surface.');
    expect(ContentRevisionsPayload('article', $created['id'], 1)['title'])->toBe('Choosing a desk lamp')
        ->and(ContentRevisionsPayload('article', $created['id'], 2)['title'])->toBe('Choosing a floor lamp')
        ->and(ContentRevisionsPayload('article', $created['id'], 3)['title'])->toBe('Choosing a desk lamp');
    expect((int) DB::table('articles')->where('id', $created['id'])->value('draft_revision'))->toBe(3);
    $this->get('/')->assertStatus(503)->assertSee('This store is getting ready')->assertDontSee('Choosing a desk lamp');
});

/** @return array<string, mixed> */
function ContentRevisionsPayload(string $type, string $id, int $revision): array
{
    $payload = ContentRevisions::payload($type, $id, $revision);
    expect($payload)->toBeArray();

    return $payload;
}

it('ThemesContentActivation restores published section drafts without rewriting older snapshots', function (): void {
    $owner = catalogInstalledOwner();
    app(SaveSectionDraft::class)->save($owner, 'home', 'content', 0, [t068Hero()]);
    app(PublishContent::class)->publish($owner, ['kind' => 'section_draft', 'page' => 'home', 'slot' => 'content', 'version' => 1]);
    app(SaveSectionDraft::class)->save($owner, 'home', 'content', 1, [t068Hero(['heading' => 'A later unpublished rewrite'])]);
    app(PublishContent::class)->publish($owner, ['kind' => 'section_draft', 'page' => 'home', 'slot' => 'content', 'version' => 2]);

    $restored = app(RestoreRevision::class)->restore($owner, [
        'kind' => 'section_draft',
        'page' => 'home',
        'slot' => 'content',
        'revision' => 1,
        'version' => 2,
    ]);
    expect($restored['published_revision'])->toBe(3);
    expect(app(ReadPublishedContent::class)->sectionDraft('home', 'content')['sections'][0]['settings']['heading'])
        ->toBe('Find your camera');
    $row = DB::table('content_drafts')->where('page_key', 'home')->where('slot', 'content')->first();
    expect((int) $row->version)->toBe(3)
        ->and((int) $row->published_revision)->toBe(3)
        ->and(str_contains((string) $row->sections, 'Find your camera'))->toBeTrue();
    expect(ContentRevisionsPayload('section_draft', 'home:content', 2)['sections'][0]['settings']['heading'])
        ->toBe('A later unpublished rewrite');
});

it('ThemesContentActivation refuses a stale restore and leaves live content unchanged', function (): void {
    $owner = catalogInstalledOwner();
    $page = app(SaveContent::class)->savePage($owner, [
        'title' => 'About the studio',
        'slug' => 'about',
        'status' => 'published',
    ]);
    app(SaveContent::class)->savePage($owner, [
        'id' => $page['id'],
        'version' => 1,
        'title' => 'About the rewritten studio',
        'slug' => 'about',
        'status' => 'draft',
    ]);
    app(PublishContent::class)->publish($owner, ['kind' => 'page', 'id' => $page['id'], 'version' => 2]);
    expect(fn () => app(RestoreRevision::class)->restore($owner, [
        'kind' => 'page',
        'id' => $page['id'],
        'revision' => 1,
        'version' => 1,
    ]))->toThrow(HttpException::class);
    expect(app(ReadPublishedContent::class)->page($page['id'])['title'])->toBe('About the rewritten studio');
    expect((int) DB::table('pages')->where('id', $page['id'])->value('published_revision'))->toBe(2)
        ->and(DB::table('content_revisions')->where('subject_type', 'page')->where('subject_id', $page['id'])->count())->toBe(2);
});

it('ThemesContentActivation activates a registered default theme and keeps the storefront closed', function (): void {
    $owner = catalogInstalledOwner();
    $activated = t068ActivateDefault($owner);
    expect($activated['theme_id'])->toBe('mrktflow/default')
        ->and($activated['active'])->toBeTrue();
    expect(DB::table('theme_activations')->whereNull('replaced_at')->value('theme_id'))->toBe('mrktflow/default');
    $this->get('/')->assertStatus(503)->assertSee('This store is getting ready');
});

it('ThemesContentActivation refuses an incompatible theme and retains the active theme', function (): void {
    $owner = catalogInstalledOwner();
    t068ActivateDefault($owner);
    $sparse = t068RegisterCoverageTheme('acme/sparse', [], ['checkout']);

    expect(fn () => app(ActivateTheme::class)->activate($owner, ['theme_id' => $sparse]))
        ->toThrow(ThemeActivationFailed::class);
    expect(DB::table('theme_activations')->whereNull('replaced_at')->value('theme_id'))->toBe('mrktflow/default')
        ->and(DB::table('theme_activations')->count())->toBe(1);
});

it('ThemesContentActivation requires a mapping for missing live section types and never discards settings', function (): void {
    $owner = catalogInstalledOwner();
    t068ActivateDefault($owner);
    $product = app(SaveProduct::class)->save($owner, catalogProductInput(catalogReadyImage($owner->id), ['status' => 'published']));
    app(SaveSectionDraft::class)->save($owner, 'home', 'content', 0, [[
        'id' => 'specs',
        'type' => 'specifications',
        'version' => 1,
        'settings' => ['heading' => 'Body specs'],
        'binding' => ['kind' => 'product', 'publicId' => $product['id']],
    ]]);
    app(PublishContent::class)->publish($owner, ['kind' => 'section_draft', 'page' => 'home', 'slot' => 'content', 'version' => 1]);

    $narrow = t068RegisterCoverageTheme('acme/narrow', ['sections' => ['hero', 'rich-text', 'featured-product']]);
    expect(fn () => app(ActivateTheme::class)->activate($owner, ['theme_id' => $narrow]))
        ->toThrow(ThemeActivationFailed::class);
    expect(fn () => app(ActivateTheme::class)->activate($owner, [
        'theme_id' => $narrow,
        'section_types' => ['specifications' => 'rich-text'],
    ]))->toThrow(ThemeActivationFailed::class);
    expect(DB::table('theme_activations')->whereNull('replaced_at')->value('theme_id'))->toBe('mrktflow/default');

    $activated = app(ActivateTheme::class)->activate($owner, [
        'theme_id' => $narrow,
        'section_types' => ['specifications' => 'featured-product'],
    ]);
    expect($activated['theme_id'])->toBe('acme/narrow')
        ->and($activated['section_types']['specifications'])->toBe('featured-product');
    expect(app(ReadPublishedContent::class)->sectionDraft('home', 'content')['sections'][0]['type'])->toBe('specifications');
    expect(DB::table('theme_activations')->whereNull('replaced_at')->value('theme_id'))->toBe('acme/narrow');
});

it('ThemesContentActivation requires a mapping for assigned templates the destination theme does not declare', function (): void {
    $owner = catalogInstalledOwner();
    t068ActivateDefault($owner);
    $product = app(SaveProduct::class)->save($owner, catalogProductInput(catalogReadyImage($owner->id), ['status' => 'published']));
    app(AssignLayout::class)->assign($owner, [
        'subject_type' => 'product',
        'subject_id' => $product['id'],
        'template_key' => 'product.compact',
    ]);
    $plain = t068RegisterCoverageTheme('acme/plain');
    expect(fn () => app(ActivateTheme::class)->activate($owner, ['theme_id' => $plain]))
        ->toThrow(ThemeActivationFailed::class);
    expect(DB::table('theme_activations')->whereNull('replaced_at')->value('theme_id'))->toBe('mrktflow/default');

    $activated = app(ActivateTheme::class)->activate($owner, [
        'theme_id' => $plain,
        'template_keys' => ['product.compact' => 'product.default'],
    ]);
    expect($activated['theme_id'])->toBe('acme/plain');
    expect(DB::table('layout_assignments')->where('subject_id', $product['id'])->value('template_key'))->toBe('product.compact');
});

it('ThemesContentActivation refuses invalid unregistered and unauthorized activation', function (): void {
    $owner = catalogInstalledOwner();
    t068ActivateDefault($owner);
    $root = t068ThemeRoot();
    [$manifest, $files] = t068Manifest(['id' => 'acme/broken', 'requires' => ['core' => '>=9.0.0 <10.0.0', 'themeApi' => '1']]);
    $directory = t068ThemeDirectory($root, 'broken', $manifest, $files);
    $broken = (new RegisterTheme($root))->register($directory);
    expect($broken->status)->toBe('invalid');

    expect(fn () => app(ActivateTheme::class)->activate($owner, ['theme_id' => 'acme/broken']))
        ->toThrow(ThemeActivationFailed::class);
    expect(fn () => app(ActivateTheme::class)->activate($owner, ['theme_id' => 'acme/missing']))
        ->toThrow(ThemeActivationFailed::class);

    $editor = t068StaffAs($owner, 'content_editor');
    expect(fn () => app(ActivateTheme::class)->activate($editor, ['theme_id' => 'mrktflow/default']))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(RestoreRevision::class)->restore($editor, [
        'kind' => 'article',
        'id' => (string) Str::uuid(),
        'revision' => 1,
        'version' => 1,
    ]))->toThrow(AuthorizationException::class);
    expect(DB::table('theme_activations')->whereNull('replaced_at')->value('theme_id'))->toBe('mrktflow/default');
});

it('ThemesContentActivation denies guests customers editors and unverified privileged staff', function (): void {
    $owner = catalogInstalledOwner();
    $article = app(SaveContent::class)->saveArticle($owner, t068ArticleInput(['status' => 'published']));
    app(SaveContent::class)->saveArticle($owner, t068ArticleInput([
        'id' => $article['id'],
        'version' => 1,
        'title' => 'Choosing a floor lamp',
        'excerpt' => 'A later unpublished rewrite.',
        'status' => 'draft',
    ]));
    app(PublishContent::class)->publish($owner, ['kind' => 'article', 'id' => $article['id'], 'version' => 2]);
    t068ActivateDefault($owner);
    $restore = ['kind' => 'article', 'id' => $article['id'], 'revision' => 1, 'version' => 2];
    $activate = ['theme_id' => 'mrktflow/default'];

    $this->post('/admin/content/restore', $restore)->assertRedirect('/admin/login');
    $this->get('/admin/themes')->assertRedirect('/admin/login');
    $this->post('/admin/themes/activate', $activate)->assertRedirect('/admin/login');

    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper',
        'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->postJson('/admin/content/restore', $restore)->assertUnauthorized();
    $this->actingAs($customer, 'customer')->getJson('/admin/themes')->assertUnauthorized();

    $fulfillment = t068StaffAs($owner, 'fulfillment');
    $this->actingAs($fulfillment, 'staff')->withSession(catalogStaffSession($fulfillment));
    $this->postJson('/admin/content/restore', $restore)->assertForbidden();
    $this->getJson('/admin/themes')->assertForbidden();
    $this->postJson('/admin/themes/activate', $activate)->assertForbidden();

    $editor = t068StaffAs($owner, 'content_editor');
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor));
    $this->postJson('/admin/content/restore', $restore)->assertForbidden();
    $this->get('/admin/themes')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Themes')
        ->where('canActivate', false)
        ->where('activeThemeId', 'mrktflow/default'));
    $this->postJson('/admin/themes/activate', $activate)->assertForbidden();
    expect((int) DB::table('articles')->where('id', $article['id'])->value('published_revision'))->toBe(2);

    $owner = t068StaffAs($owner, 'owner');
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version]);
    $this->post('/admin/content/restore', $restore)->assertRedirect('/admin/two-factor');
    $this->get('/admin/themes')->assertRedirect('/admin/two-factor');
    $this->post('/admin/themes/activate', $activate)->assertRedirect('/admin/two-factor');
    expect((int) DB::table('articles')->where('id', $article['id'])->value('published_revision'))->toBe(2);
});

it('ThemesContentActivation HTTP restores a revision and activates a compatible theme', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $page = app(SaveContent::class)->savePage($owner, [
        'title' => 'About the studio',
        'slug' => 'about',
        'status' => 'published',
    ]);
    app(SaveContent::class)->savePage($owner, [
        'id' => $page['id'],
        'version' => 1,
        'title' => 'About lighting',
        'slug' => 'about',
        'status' => 'draft',
    ]);
    app(PublishContent::class)->publish($owner, ['kind' => 'page', 'id' => $page['id'], 'version' => 2]);
    app(RegisterTheme::class)->register(base_path('themes/default'));

    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canManageContent', true));
    $this->get('/admin/content')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ContentNavigation')
        ->where('canPublish', true)
        ->where('pages.0.published_revision', 2)
        ->where('pages.0.version', 2));
    $this->post('/admin/content/restore', [
        'kind' => 'page',
        'id' => $page['id'],
        'revision' => 1,
        'version' => 2,
    ])->assertRedirect();
    expect(app(ReadPublishedContent::class)->page($page['id'])['title'])->toBe('About the studio')
        ->and((int) DB::table('pages')->where('id', $page['id'])->value('published_revision'))->toBe(3);

    $this->get('/admin/themes')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Themes')
        ->where('canActivate', true)
        ->where('activeThemeId', null));
    $this->post('/admin/themes/activate', ['theme_id' => 'mrktflow/default'])->assertRedirect('/admin/themes');
    $this->get('/admin/themes')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Themes')
        ->where('activeThemeId', 'mrktflow/default'));
    $this->get('/')->assertStatus(503);
});
