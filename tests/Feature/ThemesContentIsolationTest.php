<?php

declare(strict_types=1);

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\ThemesContent\Actions\SaveContent;
use App\Domain\ThemesContent\Actions\SaveSectionDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

/*
 * T072 isolation gate: unpublished and preview content never leak onto
 * shopper surfaces, and privileged staff still cannot skip TOTP.
 */
it('ThemesContentIsolation keeps drafts and previews off shopper surfaces and GET / closed', function (): void {
    $owner = catalogInstalledOwner();
    $page = app(SaveContent::class)->savePage($owner, [
        'title' => 'Live studio notes',
        'slug' => 'studio-notes',
        'status' => 'published',
    ]);
    app(SaveContent::class)->savePage($owner, [
        'id' => $page['id'],
        'version' => 1,
        'title' => 'Secret draft rewrite',
        'slug' => 'studio-notes',
        'status' => 'draft',
    ]);
    app(SaveSectionDraft::class)->save($owner, 'search', 'content', 0, [[
        'id' => 'intro',
        'type' => 'hero',
        'version' => 1,
        'settings' => ['heading' => 'Unpublished imported banner'],
    ]]);

    $this->get('/pages/studio-notes')->assertOk()
        ->assertSee('Live studio notes')
        ->assertDontSee('Secret draft rewrite');
    $this->get('/pages/unpublished-guide')->assertNotFound()->assertDontSee('Secret draft rewrite');
    $this->get('/admin/preview/pages/'.$page['id'])->assertRedirect('/admin/login');
    $this->get('/admin/preview/sections?page=search&slot=content')->assertRedirect('/admin/login');
    $this->get('/admin/content/import')->assertRedirect('/admin/login');
    $this->get('/admin/themes')->assertRedirect('/admin/login');

    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper',
        'email' => 'themes-gate-shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer');
    $this->getJson('/admin/preview/pages/'.$page['id'])->assertUnauthorized();
    $this->getJson('/admin/content-editor/draft?page=search&slot=content')->assertUnauthorized();
    $this->postJson('/admin/content/publish', [
        'kind' => 'page',
        'id' => $page['id'],
        'version' => 2,
    ])->assertUnauthorized();
    $this->get('/pages/studio-notes')->assertOk()->assertDontSee('Secret draft rewrite');

    $this->app['auth']->forgetGuards();
    $this->flushSession();
    $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($sitemap)->toContain('/pages/studio-notes')
        ->not->toContain('Secret draft rewrite')
        ->not->toContain('/admin/preview')
        ->not->toContain('/cart')
        ->not->toContain('/checkout')
        ->not->toContain('/account');
    $this->get('/')->assertStatus(503)
        ->assertDontSee('Secret draft rewrite')
        ->assertDontSee('Unpublished imported banner')
        ->assertDontSee('Live studio notes');
});

it('ThemesContentIsolation keeps privileged staff behind two-factor for publish restore and activation', function (): void {
    $owner = catalogInstalledOwner();
    $page = app(SaveContent::class)->savePage($owner, [
        'title' => 'About the studio',
        'slug' => 'about',
        'status' => 'published',
    ]);
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version]);
    $this->post('/admin/content/publish', [
        'kind' => 'page',
        'id' => $page['id'],
        'version' => 1,
    ])->assertRedirect('/admin/two-factor');
    $this->post('/admin/content/restore', [
        'kind' => 'page',
        'id' => $page['id'],
        'revision' => 1,
        'version' => 1,
    ])->assertRedirect('/admin/two-factor');
    $this->post('/admin/themes/activate', ['theme_id' => 'mrktflow/default'])->assertRedirect('/admin/two-factor');
    $this->get('/admin/preview/pages/'.$page['id'])->assertRedirect('/admin/two-factor');
    $this->get('/')->assertStatus(503);
    expect((int) DB::table('pages')->where('id', $page['id'])->value('published_revision'))->toBe(1)
        ->and(DB::table('theme_activations')->count())->toBe(0);
});
