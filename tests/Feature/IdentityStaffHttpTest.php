<?php

declare(strict_types=1);

use App\Application\Setup\CreateInitialOwner;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Platform\Actions\InstallStore;
use App\Domain\Platform\Actions\SetupAccess;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->withoutVite());

function staffHttpInput(): array
{
    return ['name' => 'Example Owner', 'email' => 'owner@example.test', 'password' => 'Fictional owner passphrase 471!', 'password_confirmation' => 'Fictional owner passphrase 471!'];
}

function staffHttpGrant(): array
{
    $access = app(SetupAccess::class);
    $grant = $access->unlock($access->issueSecret());
    app(InstallStore::class)->save($grant, json_decode(file_get_contents(base_path('tests/Fixtures/store-settings.json')), true, flags: JSON_THROW_ON_ERROR), 0);

    return $grant;
}

it('IdentityStaff HTTP permits first owner creation only with a protected setup session', function (): void {
    $this->postJson('/setup/owner', ['owner' => staffHttpInput()])->assertForbidden();
    $grant = staffHttpGrant();
    $this->withSession(['setup_access' => $grant])->get('/setup');
    $oldId = session()->getId();
    $this->post('/setup/owner', ['owner' => staffHttpInput()])->assertRedirect('/admin')->assertSessionMissing('setup_access');
    expect(session()->getId())->not->toBe($oldId);
    $this->assertAuthenticated('staff');
    $this->get('/setup')->assertNotFound();
    $this->postJson('/setup/owner', ['owner' => staffHttpInput()])->assertForbidden();
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')->where('staff.name', 'Example Owner')->missing('staff.password')->missing('staff.permissions'));
});

it('IdentityStaff HTTP does not flash nested credentials on failed setup', function (): void {
    $grant = staffHttpGrant();
    $input = array_replace(staffHttpInput(), ['password_confirmation' => 'PRIVATE_CONFIRMATION_SENTINEL']);
    $this->withSession(['setup_access' => $grant])->from('/setup')->post('/setup/owner', ['owner' => $input])
        ->assertRedirect('/setup')->assertSessionHasErrors('owner.password')->assertSessionMissing('_old_input.owner');
    expect(DB::table('staff_accounts')->count())->toBe(0);
    expect(DB::table('installations')->value('status'))->toBe('pending_owner');
});

it('IdentityStaff HTTP signs in with canonical email and signs out with a fresh session', function (): void {
    app(CreateInitialOwner::class)->create(staffHttpGrant(), staffHttpInput());
    $this->get('/admin')->assertRedirect('/admin/login');
    $this->get('/admin/login')->assertOk();
    $oldId = session()->getId();
    $this->post('/admin/login', ['email' => 'OWNER@example.test', 'password' => staffHttpInput()['password']])->assertRedirect('/admin');
    expect(session()->getId())->not->toBe($oldId);
    $this->get('/admin')->assertOk();
    $oldId = session()->getId();
    $this->post('/admin/logout')->assertRedirect('/admin/login');
    expect(session()->getId())->not->toBe($oldId);
    $this->assertGuest('staff');
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('IdentityStaff HTTP gives generic login failures and throttles repeated attempts', function (): void {
    $owner = app(CreateInitialOwner::class)->create(staffHttpGrant(), staffHttpInput());
    foreach (['unknown@example.test', $owner->email] as $email) {
        $this->postJson('/admin/login', ['email' => $email, 'password' => 'Wrong fictional passphrase'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'The sign-in details could not be verified.');
    }
    DB::table('staff_accounts')->where('id', $owner->id)->update(['is_active' => false]);
    $this->postJson('/admin/login', ['email' => $owner->email, 'password' => staffHttpInput()['password']])
        ->assertUnprocessable()->assertJsonPath('errors.email.0', 'The sign-in details could not be verified.');
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->postJson('/admin/login', ['email' => $owner->email, 'password' => 'Wrong fictional passphrase'])->assertUnprocessable();
    }
    $this->postJson('/admin/login', ['email' => $owner->email, 'password' => 'Wrong fictional passphrase'])->assertStatus(429);
    $this->assertGuest('staff');
});

it('IdentityStaff HTTP rejects disabled and outdated staff sessions on the next request', function (): void {
    $owner = app(CreateInitialOwner::class)->create(staffHttpGrant(), staffHttpInput());
    $this->post('/admin/login', ['email' => $owner->email, 'password' => staffHttpInput()['password']]);
    DB::table('staff_accounts')->where('id', $owner->id)->update(['session_version' => 2]);
    $this->get('/admin')->assertRedirect('/admin/login');
    $this->assertGuest('staff');
    $this->post('/admin/login', ['email' => $owner->email, 'password' => staffHttpInput()['password']]);
    DB::table('staff_accounts')->where('id', $owner->id)->update(['is_active' => false]);
    $this->getJson('/admin')->assertUnauthorized();
    $this->assertGuest('staff');
});

it('IdentityStaff HTTP denies account injection and administrative access without permission', function (): void {
    $owner = app(CreateInitialOwner::class)->create(staffHttpGrant(), staffHttpInput());
    $this->postJson('/admin/login', ['email' => $owner->email, 'password' => 'Wrong fictional passphrase', 'role' => 'owner'])->assertUnprocessable();
    $this->assertGuest('staff');
    $foreign = new User;
    $foreign->forceFill(['id' => $owner->id, 'role' => 'owner']);
    $this->actingAs($foreign, 'staff')->withSession(['staff_session_version' => 1])->getJson('/admin')->assertUnauthorized();
    DB::table('staff_accounts')->where('id', $owner->id)->update(['permissions' => json_encode(['staff.manage'])]);
    $owner = StaffAccount::query()->findOrFail($owner->id);
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1])->getJson('/admin')->assertForbidden();
});

it('IdentityStaff HTTP preserves password bytes from owner setup through sign-in', function (): void {
    $input = array_replace(staffHttpInput(), ['password' => '  Fictional whitespace passphrase  ', 'password_confirmation' => '  Fictional whitespace passphrase  ']);
    $this->withSession(['setup_access' => staffHttpGrant()])->post('/setup/owner', ['owner' => $input])->assertRedirect('/admin');
    $this->post('/admin/logout');
    $this->post('/admin/login', ['email' => $input['email'], 'password' => $input['password']])->assertRedirect('/admin');
    $this->assertAuthenticated('staff');
});

it('IdentityStaff HTTP leaves anonymous guest intent intact when requesting administration', function (): void {
    app(CreateInitialOwner::class)->create(staffHttpGrant(), staffHttpInput());
    $this->withSession(['guest_cart_intent' => 'FICTIONAL_CART_INTENT'])->get('/admin')->assertRedirect('/admin/login')
        ->assertSessionHas('guest_cart_intent', 'FICTIONAL_CART_INTENT');
});

it('IdentityStaff HTTP isolates owner setup rate limits from settings saves', function (): void {
    $secret = app(SetupAccess::class)->issueSecret();
    $this->post('/setup/access', ['setup_secret' => $secret])->assertRedirect('/setup');
    $settings = json_decode(file_get_contents(base_path('tests/Fixtures/store-settings.json')), true, flags: JSON_THROW_ON_ERROR);
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->post('/setup/settings', ['settings' => $settings, 'version' => 0])->assertRedirect('/setup');
    }
    $this->post('/setup/owner', ['owner' => staffHttpInput()])->assertRedirect('/admin');
});

it('IdentityStaff HTTP rejects forged owner, sign-in and sign-out requests', function (): void {
    app(CreateInitialOwner::class)->create(staffHttpGrant(), staffHttpInput());
    $this->app->instance('env', 'local');
    $this->postJson('/setup/owner', ['owner' => staffHttpInput()])->assertStatus(419);
    $this->postJson('/admin/login', ['email' => 'owner@example.test', 'password' => staffHttpInput()['password']])->assertStatus(419);
    $this->postJson('/admin/logout')->assertStatus(419);
});

it('IdentityStaff HTTP protects staff management before lookup and exposes only safe account fields', function (): void {
    $owner = app(CreateInitialOwner::class)->create(staffHttpGrant(), staffHttpInput());
    $this->get('/admin/staff')->assertRedirect('/admin/login');
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1])->get('/admin/staff')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Staff')->where('accounts.data.0.email', $owner->email)
            ->missing('accounts.data.0.password')->missing('accounts.data.0.remember_token')->missing('accounts.data.0.permissions'));
    foreach (['manager', 'content_editor', 'fulfillment'] as $role) {
        DB::table('staff_accounts')->where('id', $owner->id)->update(['role' => $role, 'permissions' => json_encode(StaffPermissions::forRole($role))]);
        $this->getJson('/admin/staff')->assertForbidden();
        foreach ([$owner->id, '00000000-0000-4000-8000-000000000000'] as $id) {
            $this->patchJson('/admin/staff/'.$id, ['access' => ['role' => 'owner', 'is_active' => true, 'version' => 1]])->assertForbidden();
        }
    }
});

it('IdentityStaff HTTP preserves the final owner and rejects outdated management edits', function (): void {
    $owner = app(CreateInitialOwner::class)->create(staffHttpGrant(), staffHttpInput());
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->patchJson('/admin/staff/'.$owner->id, ['access' => ['role' => 'manager', 'is_active' => true, 'version' => 1]])->assertConflict();
    $this->from('/admin/staff')->patch('/admin/staff/'.$owner->id, ['access' => ['role' => 'owner', 'is_active' => false, 'version' => 1]])
        ->assertRedirect('/admin/staff')->assertSessionHasErrors('access');
    $staff = new StaffAccount;
    $staff->forceFill(['name' => 'Example Editor', 'email' => 'editor@example.test', 'password' => Hash::make(staffHttpInput()['password']),
        'role' => 'content_editor', 'permissions' => StaffPermissions::forRole('content_editor'), 'is_active' => true])->save();
    $this->patch('/admin/staff/'.$staff->id, ['access' => ['role' => 'fulfillment', 'is_active' => true, 'version' => 1]])->assertRedirect('/admin/staff');
    expect($staff->fresh()->role)->toBe('fulfillment');
    expect($staff->fresh()->session_version)->toBe(2);
    $this->patchJson('/admin/staff/'.$staff->id, ['access' => ['role' => 'owner', 'is_active' => true, 'version' => 1]])->assertConflict();
    expect($staff->fresh()->role)->toBe('fulfillment');
});
