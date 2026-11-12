<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\ThemesContent\Actions\ListArticles;
use App\Domain\ThemesContent\Actions\PublishContent;
use App\Domain\ThemesContent\Actions\ReadPublishedContent;
use App\Domain\ThemesContent\Actions\SaveContent;
use App\Domain\ThemesContent\Actions\SaveSectionDraft;
use App\Domain\ThemesContent\Exceptions\SectionValidationFailed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->withoutVite();
});

/** @param  list<string>|null  $permissions */
function t067StaffAs(StaffAccount $staff, string $role, ?array $permissions = null): StaffAccount
{
    DB::table('staff_accounts')->where('id', $staff->id)->update([
        'role' => $role,
        'permissions' => json_encode($permissions ?? StaffPermissions::forRole($role)),
    ]);

    return $staff->fresh();
}

/** @param  array<string, mixed>  $overrides */
function t067PageInput(array $overrides = []): array
{
    return array_replace([
        'title' => 'About the studio',
        'slug' => 'about',
        'status' => 'draft',
    ], $overrides);
}

/** @param  array<string, mixed>  $overrides */
function t067ArticleInput(array $overrides = []): array
{
    return array_replace([
        'title' => 'Choosing a desk lamp',
        'slug' => 'desk-lamp-guide',
        'excerpt' => 'Start with task lighting at the work surface.',
        'status' => 'draft',
    ], $overrides);
}

/** @return array<string, mixed> */
function t067Hero(array $settings = []): array
{
    return ['id' => 'intro', 'type' => 'hero', 'version' => 1, 'settings' => array_merge(['heading' => 'Find your camera'], $settings)];
}

it('ThemesContentPublish keeps later saves as drafts until an explicit publish switches the live revision', function (): void {
    $owner = catalogInstalledOwner();
    $created = app(SaveContent::class)->saveArticle($owner, t067ArticleInput(['status' => 'published']));
    expect($created['status'])->toBe('published')
        ->and($created['version'])->toBe(1);
    expect(app(ListArticles::class)->published(8)[0]['title'])->toBe('Choosing a desk lamp');

    $draft = app(SaveContent::class)->saveArticle($owner, t067ArticleInput([
        'id' => $created['id'],
        'version' => 1,
        'title' => 'Choosing a floor lamp',
        'excerpt' => 'A later unpublished rewrite.',
        'status' => 'draft',
    ]));
    expect($draft['version'])->toBe(2)
        ->and($draft['status'])->toBe('published');
    expect(app(ListArticles::class)->published(8))->toHaveCount(1)
        ->and(app(ListArticles::class)->published(8)[0]['title'])->toBe('Choosing a desk lamp')
        ->and(app(ListArticles::class)->published(8)[0]['excerpt'])->toBe('Start with task lighting at the work surface.');
    $live = app(ReadPublishedContent::class)->article($created['id']);
    expect($live['revision'])->toBe(1)
        ->and($live['title'])->toBe('Choosing a desk lamp');
    expect(DB::table('content_revisions')->where('subject_type', 'article')->where('subject_id', $created['id'])->count())->toBe(2);

    $published = app(PublishContent::class)->publish($owner, [
        'kind' => 'article',
        'id' => $created['id'],
        'version' => 2,
    ]);
    expect($published['published_revision'])->toBe(2)
        ->and($published['version'])->toBe(2);
    expect(app(ListArticles::class)->published(8)[0]['title'])->toBe('Choosing a floor lamp')
        ->and(app(ListArticles::class)->published(8)[0]['excerpt'])->toBe('A later unpublished rewrite.');
    $this->get('/')->assertStatus(503)->assertSee('This store is getting ready')->assertDontSee('Choosing a floor lamp');
});

it('ThemesContentPublish saves a section draft without moving live published sections', function (): void {
    $owner = catalogInstalledOwner();
    app(SaveSectionDraft::class)->save($owner, 'home', 'content', 0, [t067Hero()]);
    $first = app(PublishContent::class)->publish($owner, [
        'kind' => 'section_draft',
        'page' => 'home',
        'slot' => 'content',
        'version' => 1,
    ]);
    expect($first['published_revision'])->toBe(1);
    app(SaveSectionDraft::class)->save($owner, 'home', 'content', 1, [t067Hero(['heading' => 'A later unpublished rewrite'])]);
    $live = app(ReadPublishedContent::class)->sectionDraft('home', 'content');
    expect($live['revision'])->toBe(1)
        ->and($live['sections'][0]['settings']['heading'])->toBe('Find your camera');
    $row = DB::table('content_drafts')->where('page_key', 'home')->where('slot', 'content')->first();
    expect($row->version)->toBe(2)
        ->and($row->published_revision)->toBe(1)
        ->and(str_contains((string) $row->sections, 'A later unpublished rewrite'))->toBeTrue();

    $second = app(PublishContent::class)->publish($owner, [
        'kind' => 'section_draft',
        'page' => 'home',
        'slot' => 'content',
        'version' => 2,
    ]);
    expect($second['published_revision'])->toBe(2);
    expect(app(ReadPublishedContent::class)->sectionDraft('home', 'content')['sections'][0]['settings']['heading'])
        ->toBe('A later unpublished rewrite');
});

it('ThemesContentPublish refuses a stale expected version and leaves live content unchanged', function (): void {
    $owner = catalogInstalledOwner();
    $page = app(SaveContent::class)->savePage($owner, t067PageInput(['status' => 'published']));
    app(SaveContent::class)->savePage($owner, t067PageInput([
        'id' => $page['id'],
        'version' => 1,
        'title' => 'About the rewritten studio',
        'status' => 'draft',
    ]));
    expect(fn () => app(PublishContent::class)->publish($owner, [
        'kind' => 'page',
        'id' => $page['id'],
        'version' => 1,
    ]))->toThrow(HttpException::class);
    expect(app(ReadPublishedContent::class)->page($page['id'])['title'])->toBe('About the studio');
    expect((int) DB::table('pages')->where('id', $page['id'])->value('published_revision'))->toBe(1)
        ->and((int) DB::table('pages')->where('id', $page['id'])->value('draft_revision'))->toBe(2);
});

it('ThemesContentPublish validates the draft and writes nothing when a binding is no longer published', function (): void {
    $owner = catalogInstalledOwner();
    $category = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    app(SaveSectionDraft::class)->save($owner, 'home', 'content', 0, [[
        'id' => 'grid',
        'type' => 'product-grid',
        'version' => 1,
        'settings' => ['heading' => 'Shop'],
        'binding' => ['kind' => 'category', 'publicId' => $category['id']],
    ]]);
    app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'id' => $category['id'],
        'version' => $category['version'],
        'status' => 'draft',
    ]));
    expect(fn () => app(PublishContent::class)->publish($owner, [
        'kind' => 'section_draft',
        'page' => 'home',
        'slot' => 'content',
        'version' => 1,
    ]))->toThrow(SectionValidationFailed::class);
    $row = DB::table('content_drafts')->where('page_key', 'home')->where('slot', 'content')->first();
    expect($row->published_revision)->toBeNull()
        ->and($row->version)->toBe(1);
});

it('ThemesContentPublish denies guests customers editors and unverified privileged staff', function (): void {
    $owner = catalogInstalledOwner();
    $article = app(SaveContent::class)->saveArticle($owner, t067ArticleInput());
    $payload = ['kind' => 'article', 'id' => $article['id'], 'version' => 1];

    $this->post('/admin/content/publish', $payload)->assertRedirect('/admin/login');
    $this->get('/admin/preview/articles/'.$article['id'])->assertRedirect('/admin/login');

    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper',
        'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->postJson('/admin/content/publish', $payload)->assertUnauthorized();
    $this->actingAs($customer, 'customer')->getJson('/admin/preview/articles/'.$article['id'])->assertUnauthorized();

    $fulfillment = t067StaffAs($owner, 'fulfillment');
    $this->actingAs($fulfillment, 'staff')->withSession(catalogStaffSession($fulfillment));
    $this->postJson('/admin/content/publish', $payload)->assertForbidden();
    $this->getJson('/admin/preview/articles/'.$article['id'])->assertForbidden();

    $editor = t067StaffAs($owner, 'content_editor');
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor));
    $this->postJson('/admin/content/publish', $payload)->assertForbidden();
    expect(fn () => app(PublishContent::class)->publish($editor, $payload))->toThrow(AuthorizationException::class);
    expect((int) DB::table('articles')->where('id', $article['id'])->value('published_revision') ?: 0)->toBe(0);

    $owner = t067StaffAs($owner, 'owner');
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version]);
    $this->post('/admin/content/publish', $payload)->assertRedirect('/admin/two-factor');
    $this->get('/admin/preview/articles/'.$article['id'])->assertRedirect('/admin/two-factor');
    expect(DB::table('articles')->where('id', $article['id'])->value('published_revision'))->toBeNull();
});

it('ThemesContentPublish preview is authorized noindex no-store and shows the draft not the live revision', function (): void {
    $owner = catalogInstalledOwner();
    $page = app(SaveContent::class)->savePage($owner, t067PageInput(['status' => 'published']));
    app(SaveContent::class)->savePage($owner, t067PageInput([
        'id' => $page['id'],
        'version' => 1,
        'title' => 'Draft only rewrite',
        'status' => 'draft',
    ]));
    $reader = t067StaffAs($owner, 'content_editor', ['admin.access', 'content.read']);
    $this->actingAs($reader, 'staff')->withSession(catalogStaffSession($reader));

    $preview = $this->get('/admin/preview/pages/'.$page['id']);
    $preview->assertOk()
        ->assertSee('Draft only rewrite')
        ->assertSee('Content preview')
        ->assertDontSee('This store is getting ready')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($preview->headers->get('Cache-Control'))->toContain('no-store');
    expect(app(ReadPublishedContent::class)->page($page['id'])['title'])->toBe('About the studio');
    $this->get('/')->assertStatus(503)->assertDontSee('Draft only rewrite');
});

it('ThemesContentPublish HTTP lets a publisher switch a validated revision and keeps concurrent documents', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $page = app(SaveContent::class)->savePage($owner, t067PageInput(['status' => 'published']));
    $article = app(SaveContent::class)->saveArticle($owner, t067ArticleInput(['status' => 'published']));
    app(SaveContent::class)->savePage($owner, t067PageInput([
        'id' => $page['id'],
        'version' => 1,
        'title' => 'About lighting',
        'status' => 'draft',
    ]));
    app(SaveContent::class)->saveArticle($owner, t067ArticleInput([
        'id' => $article['id'],
        'version' => 1,
        'title' => 'Choosing a floor lamp',
        'status' => 'draft',
    ]));
    app(SaveSectionDraft::class)->save($owner, 'home', 'content', 0, [t067Hero()]);

    $this->get('/admin/content')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ContentNavigation')
        ->where('canPublish', true)
        ->where('pages.0.published_revision', 1)
        ->where('pages.0.version', 2));
    $this->get('/admin/content-editor')->assertOk()->assertInertia(fn (AssertableInertia $editor) => $editor
        ->component('ContentEditor')
        ->where('canPublish', true)
        ->where('initial.publishedRevision', 0));

    $this->post('/admin/content/publish', ['kind' => 'page', 'id' => $page['id'], 'version' => 2])->assertRedirect();
    $this->post('/admin/content/publish', ['kind' => 'article', 'id' => $article['id'], 'version' => 2])->assertRedirect();
    $this->post('/admin/content-editor/publish', ['page' => 'home', 'slot' => 'content', 'version' => 1])->assertRedirect();

    expect(app(ReadPublishedContent::class)->page($page['id'])['title'])->toBe('About lighting')
        ->and(app(ReadPublishedContent::class)->article($article['id'])['title'])->toBe('Choosing a floor lamp')
        ->and(app(ReadPublishedContent::class)->sectionDraft('home', 'content')['revision'])->toBe(1);
    $this->get('/admin/preview/sections?page=home&slot=content')
        ->assertOk()
        ->assertSee('Find your camera')
        ->assertSee('Content preview')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get('/')->assertStatus(503);
});

it('ThemesContentPublish serializes concurrent editors so one draft wins, the other keeps work, and live content stays stable', function (): void {
    $owner = catalogInstalledOwner();
    $article = app(SaveContent::class)->saveArticle($owner, t067ArticleInput(['status' => 'published']));
    $titles = ['Draft from editor one', 'Draft from editor two'];
    $processes = [];
    $readyFiles = [];
    foreach ($titles as $title) {
        $ready = base_path('.runtime/content-'.str_replace(' ', '-', $title).'.ready');
        @unlink($ready);
        $readyFiles[] = $ready;
    }
    DB::beginTransaction();
    try {
        DB::table('articles')->where('id', $article['id'])->lockForUpdate()->first();
        foreach ($titles as $title) {
            $process = new Process([
                base_path('bin/php'),
                base_path('tests/Fixtures/content-publish-race.php'),
                $owner->id,
                $article['id'],
                $title,
                '--env=testing',
            ], base_path(), ['APP_ENV' => 'testing']);
            $process->setTimeout(15)->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 8;
        while ((! is_file($readyFiles[0]) || ! is_file($readyFiles[1])) && microtime(true) < $deadline) {
            expect($processes[0]->isRunning() && $processes[1]->isRunning())->toBeTrue();
            usleep(20000);
        }
        expect(is_file($readyFiles[0]) && is_file($readyFiles[1]))->toBeTrue();
        DB::commit();
        $results = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0);
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        $statuses = array_column($results, 'status');
        sort($statuses);
        expect($statuses)->toBe([200, 409]);
        $winner = null;
        foreach ($results as $result) {
            if ($result['status'] === 200) {
                $winner = $result['title'];
            }
        }
        expect($winner)->toBeIn($titles);
        $row = DB::table('articles')->where('id', $article['id'])->first();
        $live = app(ReadPublishedContent::class)->article($article['id']);
        expect($live['title'])->toBe('Choosing a desk lamp')
            ->and((int) $row->published_revision)->toBe(1)
            ->and((int) $row->version)->toBe(2)
            ->and((string) $row->title)->toBe($winner);
        $this->get('/')->assertStatus(503)->assertDontSee('Draft from editor one')->assertDontSee('Draft from editor two');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        foreach ($readyFiles as $ready) {
            @unlink($ready);
        }
    }
});
