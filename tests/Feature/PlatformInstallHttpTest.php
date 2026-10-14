<?php

declare(strict_types=1);

use App\Domain\Platform\Actions\SetupAccess;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => $this->withoutVite());

it('PlatformInstall HTTP hides setup until a CLI secret exists and denies ungranted writes', function (): void {
    $this->get('/setup')->assertNotFound();
    $this->postJson('/setup/access', ['setup_secret' => str_repeat('0', 64)])->assertStatus(422);
    $this->postJson('/setup/settings', ['settings' => [], 'version' => 0])->assertForbidden();
});

it('PlatformInstall HTTP exposes no settings or secret material before unlock', function (): void {
    $secret = app(SetupAccess::class)->issueSecret();
    $this->get('/setup')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Install')->where('unlocked', false)->where('settings', null)
        ->where('checks', [])->missing('secret_digest')->missing('generation'))
        ->assertDontSee($secret);
});

it('PlatformInstall HTTP rotates the session and supports safe settings resume', function (): void {
    $secret = app(SetupAccess::class)->issueSecret();
    $this->get('/setup');
    $oldId = session()->getId();
    $this->post('/setup/access', ['setup_secret' => $secret])->assertRedirect('/setup');
    expect(session()->getId())->not->toBe($oldId);
    $this->get('/setup')->assertInertia(fn (AssertableInertia $page) => $page->where('unlocked', true));
    $settings = json_decode(file_get_contents(base_path('tests/Fixtures/store-settings.json')), true);
    $this->post('/setup/settings', ['settings' => $settings, 'version' => 0])->assertRedirect('/setup');
    $this->get('/setup')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('version', 1)->where('settings.name', 'Example Supply')->where('status', 'pending_owner'));
    $this->post('/setup/settings', ['settings' => $settings, 'version' => 0])->assertRedirect('/setup');
    expect(DB::table('store_settings')->value('version'))->toBe(1);
});

it('PlatformInstall HTTP does not flash installation secrets on validation failure', function (): void {
    app(SetupAccess::class)->issueSecret();
    $this->from('/setup')->post('/setup/access', ['setup_secret' => 'PRIVATE_SETUP_SENTINEL'])
        ->assertRedirect('/setup')->assertSessionHasErrors('setup_secret')
        ->assertSessionMissing('_old_input.setup_secret');
    $this->get('/setup')->assertDontSee('PRIVATE_SETUP_SENTINEL');
});

it('PlatformInstall HTTP clears revoked session access and closes completed setup', function (): void {
    $access = app(SetupAccess::class);
    $secret = $access->issueSecret();
    $this->post('/setup/access', ['setup_secret' => $secret])->assertRedirect('/setup');
    $access->issueSecret();
    $this->get('/setup')->assertInertia(fn (AssertableInertia $page) => $page->where('unlocked', false));
    $this->postJson('/setup/settings', ['settings' => [], 'version' => 0])->assertForbidden();
    DB::table('installations')->where('id', 1)->update(['status' => 'complete', 'completed_at' => now()]);
    $this->get('/setup')->assertNotFound();
    $this->postJson('/setup/access', ['setup_secret' => $secret])->assertStatus(422);
});

it('PlatformInstall HTTP limits unlock attempts', function (): void {
    app(SetupAccess::class)->issueSecret();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/setup/access', ['setup_secret' => str_repeat('0', 64)])->assertStatus(422);
    }
    $this->postJson('/setup/access', ['setup_secret' => str_repeat('0', 64)])->assertStatus(429);
});

it('PlatformInstall HTTP gives stale edits a recoverable form error and preserves newer settings', function (): void {
    $secret = app(SetupAccess::class)->issueSecret();
    $this->post('/setup/access', ['setup_secret' => $secret])->assertRedirect('/setup');
    $settings = json_decode(file_get_contents(base_path('tests/Fixtures/store-settings.json')), true);
    $this->post('/setup/settings', ['settings' => $settings, 'version' => 0])->assertRedirect('/setup');
    $this->from('/setup')->post('/setup/settings', ['settings' => array_replace($settings, ['name' => 'Stale change']), 'version' => 0])
        ->assertRedirect('/setup')->assertSessionHasErrors('settings');
    expect(json_decode(DB::table('store_settings')->value('values'), true)['name'])->toBe('Example Supply');
    $this->postJson('/setup/settings', ['settings' => array_replace($settings, ['name' => 'Stale change']), 'version' => 0])->assertConflict();
});

it('PlatformInstall HTTP redirects expired browser setup access back to unlock', function (): void {
    $secret = app(SetupAccess::class)->issueSecret();
    $this->post('/setup/access', ['setup_secret' => $secret]);
    $this->travel(16)->minutes();
    $this->post('/setup/settings', ['settings' => [], 'version' => 0])->assertRedirect('/setup')->assertSessionHasErrors('setup_secret');
    $this->get('/setup')->assertInertia(fn (AssertableInertia $page) => $page->where('unlocked', false)->where('settings', null));
});
