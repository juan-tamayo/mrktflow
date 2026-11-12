<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\ThemesContent\Actions\RenderSections;
use App\Domain\ThemesContent\Actions\SaveContent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
});

/** @param  list<string>|null  $permissions */
function t065StaffAs(StaffAccount $staff, string $role, ?array $permissions = null): StaffAccount
{
    DB::table('staff_accounts')->where('id', $staff->id)->update([
        'role' => $role,
        'permissions' => json_encode($permissions ?? StaffPermissions::forRole($role)),
    ]);

    return $staff->fresh();
}

/** @param  array<string, mixed>  $overrides */
function t065PageInput(array $overrides = []): array
{
    return array_replace([
        'title' => 'About the studio',
        'slug' => 'about',
        'status' => 'published',
    ], $overrides);
}

/** @param  array<string, mixed>  $overrides */
function t065ArticleInput(array $overrides = []): array
{
    return array_replace([
        'title' => 'Choosing a desk lamp',
        'slug' => 'desk-lamp-guide',
        'excerpt' => 'Start with task lighting at the work surface.',
        'status' => 'published',
    ], $overrides);
}

/** @param  array<string, mixed>  $overrides */
function t065MenuInput(array $overrides = []): array
{
    return array_replace([
        'handle' => 'main',
        'title' => 'Main menu',
        'items' => [
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'About', 'url' => '/pages/about'],
        ],
    ], $overrides);
}

it('ThemesContentPage creates page article and menu records with validated paths', function (): void {
    $owner = catalogInstalledOwner();
    $page = app(SaveContent::class)->savePage($owner, t065PageInput());
    expect($page['path'])->toBe('pages/about')
        ->and($page['status'])->toBe('published')
        ->and($page['version'])->toBe(1);
    $article = app(SaveContent::class)->saveArticle($owner, t065ArticleInput());
    expect($article['path'])->toBe('journal/desk-lamp-guide')
        ->and($article['title'])->toBe('Choosing a desk lamp');
    $menu = app(SaveContent::class)->saveMenu($owner, t065MenuInput());
    expect($menu['handle'])->toBe('main')
        ->and($menu['item_count'])->toBe(2);
    expect(DB::table('pages')->count())->toBe(1)
        ->and(DB::table('articles')->count())->toBe(1)
        ->and(DB::table('menus')->count())->toBe(1)
        ->and(DB::table('menu_items')->count())->toBe(2);
    $this->get('/')->assertOk()->assertDontSee('About the studio');
});

it('ThemesContentPage rejects path collision with reserved routes and the category tree', function (): void {
    $owner = catalogInstalledOwner();
    app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    app(SaveContent::class)->savePage($owner, t065PageInput());
    expect(fn () => app(SaveContent::class)->savePage($owner, t065PageInput(['title' => 'Also about', 'slug' => 'about'])))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveContent::class)->savePage($owner, t065PageInput(['title' => 'Admin', 'slug' => 'admin'])))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveContent::class)->savePage($owner, t065PageInput(['title' => 'Cart', 'slug' => 'cart'])))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveContent::class)->savePage($owner, t065PageInput(['title' => 'Lighting page', 'slug' => 'lighting'])))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveContent::class)->saveArticle($owner, t065ArticleInput(['title' => 'Cart notes', 'slug' => 'cart'])))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveContent::class)->saveArticle($owner, t065ArticleInput(['title' => 'Lighting notes', 'slug' => 'lighting'])))
        ->toThrow(ValidationException::class);
    expect(DB::table('pages')->count())->toBe(1)
        ->and(DB::table('articles')->count())->toBe(0);
});

it('ThemesContentPage rejects an invalid menu URL and persists nothing', function (): void {
    $owner = catalogInstalledOwner();
    foreach ([
        [['label' => 'Admin', 'url' => '/admin']],
        [['label' => 'Remote', 'url' => 'https://evil.example']],
        [['label' => 'Script', 'url' => 'javascript:alert(1)']],
        [['label' => 'Traversal', 'url' => '/../secret']],
        [['label' => 'Empty', 'url' => '']],
        [['label' => 'Setup', 'url' => '/setup']],
    ] as $items) {
        expect(fn () => app(SaveContent::class)->saveMenu($owner, t065MenuInput(['items' => $items])))
            ->toThrow(ValidationException::class);
    }
    expect(DB::table('menus')->count())->toBe(0)
        ->and(DB::table('menu_items')->count())->toBe(0);
    $menu = app(SaveContent::class)->saveMenu($owner, t065MenuInput([
        'items' => [
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'Shop', 'url' => '/search'],
            ['label' => 'About', 'url' => '/pages/about'],
        ],
    ]));
    expect($menu['item_count'])->toBe(3);
});

it('ThemesContentPage article list is empty until a published article exists', function (): void {
    $owner = catalogInstalledOwner();
    $payload = [['id' => 'guides', 'type' => 'article-list', 'version' => 1, 'settings' => ['heading' => 'Guides', 'limit' => 3]]];
    $empty = app(RenderSections::class)->render('home', 'content', $payload)->render();
    expect($empty)->toContain('data-section-type="article-list"')
        ->and($empty)->toContain('No articles yet.')
        ->and($empty)->not->toContain('Choosing a desk lamp');

    app(SaveContent::class)->saveArticle($owner, t065ArticleInput(['status' => 'draft', 'slug' => 'draft-notes']));
    $stillEmpty = app(RenderSections::class)->render('home', 'content', $payload)->render();
    expect($stillEmpty)->toContain('No articles yet.');

    app(SaveContent::class)->saveArticle($owner, t065ArticleInput(['status' => 'published']));
    $listed = app(RenderSections::class)->render('home', 'content', $payload)->render();
    expect($listed)->toContain('Choosing a desk lamp')
        ->and($listed)->toContain('/journal/desk-lamp-guide')
        ->and($listed)->not->toContain('No articles yet.');
});

it('ThemesContentPage denies guests customers and staff without content access', function (): void {
    $owner = catalogInstalledOwner();

    $this->get('/admin/content')->assertRedirect('/admin/login');
    $this->post('/admin/content/pages', ['page' => t065PageInput()])->assertRedirect('/admin/login');

    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper',
        'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->postJson('/admin/content/pages', ['page' => t065PageInput()])
        ->assertUnauthorized();

    $fulfillment = t065StaffAs($owner, 'fulfillment');
    $this->actingAs($fulfillment, 'staff')->withSession(catalogStaffSession($fulfillment));
    $this->get('/admin/content')->assertForbidden();
    $this->postJson('/admin/content/pages', ['page' => t065PageInput()])->assertForbidden();
    $this->postJson('/admin/content/articles', ['article' => t065ArticleInput()])->assertForbidden();
    $this->postJson('/admin/content/menus', ['menu' => t065MenuInput()])->assertForbidden();

    expect(fn () => app(SaveContent::class)->savePage($fulfillment, t065PageInput()))
        ->toThrow(AuthorizationException::class);
});

it('ThemesContentPage keeps privileged staff behind two-factor', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version]);
    $this->get('/admin/content')->assertRedirect('/admin/two-factor');
    $this->post('/admin/content/pages', ['page' => t065PageInput()])->assertRedirect('/admin/two-factor');
    expect(DB::table('pages')->count())->toBe(0);
});

it('ThemesContentPage lets read-only staff view but not save', function (): void {
    $owner = catalogInstalledOwner();
    $reader = t065StaffAs($owner, 'content_editor', ['admin.access', 'content.read']);
    $this->actingAs($reader, 'staff')->withSession(catalogStaffSession($reader));
    $this->get('/admin/content')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('ContentNavigation')
            ->where('canWrite', false)
            ->where('pages', [])
            ->where('articles', [])
            ->where('menus', []));
    $this->postJson('/admin/content/pages', ['page' => t065PageInput()])->assertForbidden();
    expect(DB::table('pages')->count())->toBe(0);
});

it('ThemesContentPage HTTP lets authorized staff save content and keeps the storefront homepage closed to CMS pages', function (): void {
    $owner = catalogInstalledOwner();
    $editor = t065StaffAs($owner, 'content_editor');
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor));

    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canManageContent', true));
    $this->get('/admin/content')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ContentNavigation')
        ->where('canWrite', true));

    $this->post('/admin/content/pages', ['page' => t065PageInput()])->assertRedirect();
    $this->post('/admin/content/articles', ['article' => t065ArticleInput()])->assertRedirect();
    $this->post('/admin/content/menus', ['menu' => t065MenuInput()])->assertRedirect();

    $this->get('/admin/content')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ContentNavigation')
        ->has('pages', 1)
        ->has('articles', 1)
        ->has('menus', 1)
        ->where('pages.0.path', 'pages/about')
        ->where('articles.0.path', 'journal/desk-lamp-guide')
        ->where('menus.0.handle', 'main'));

    $this->from('/admin/content')->post('/admin/content/pages', ['page' => t065PageInput(['title' => 'Admin', 'slug' => 'admin'])])
        ->assertRedirect('/admin/content')
        ->assertSessionHasErrors();
    $this->from('/admin/content')->post('/admin/content/menus', ['menu' => t065MenuInput([
        'handle' => 'footer',
        'items' => [['label' => 'Remote', 'url' => 'https://evil.example']],
    ])])->assertRedirect('/admin/content')->assertSessionHasErrors();

    expect(DB::table('pages')->count())->toBe(1)
        ->and(DB::table('menus')->where('handle', 'footer')->exists())->toBeFalse();
    $this->get('/')->assertOk()->assertDontSee('About the studio');
});
