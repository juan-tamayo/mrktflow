<?php

declare(strict_types=1);

use App\Application\Setup\CreateInitialOwner;
use App\Domain\Identity\Actions\InviteStaff;
use App\Domain\Identity\Actions\ManageTwoFactor;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffClock;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Identity\Support\StaffTotp;
use App\Domain\Platform\Actions\InstallStore;
use App\Domain\Platform\Actions\SetupAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->withoutVite());

function twoFactorPassword(): string
{
    return 'Fictional owner passphrase 471!';
}

function twoFactorGrant(): array
{
    $access = app(SetupAccess::class);
    $grant = $access->unlock($access->issueSecret());
    app(InstallStore::class)->save($grant, json_decode(file_get_contents(base_path('tests/Fixtures/store-settings.json')), true, flags: JSON_THROW_ON_ERROR), 0);

    return $grant;
}

function twoFactorOwner(): StaffAccount
{
    return app(CreateInitialOwner::class)->create(twoFactorGrant(), [
        'name' => 'Example Owner', 'email' => 'owner@example.test',
        'password' => twoFactorPassword(), 'password_confirmation' => twoFactorPassword(),
    ]);
}

function twoFactorLimited(string $role): StaffAccount
{
    $staff = new StaffAccount;
    $staff->forceFill([
        'name' => 'Example Staff', 'email' => $role.'@example.test', 'password' => Hash::make(twoFactorPassword()),
        'role' => $role, 'permissions' => StaffPermissions::forRole($role), 'is_active' => true,
    ])->save();

    return $staff;
}

it('IdentityTwoFactor uses a bounded SHA-1 TOTP profile and RFC 6238 vectors', function (): void {
    expect(StaffTotp::DIGITS)->toBe(6);
    expect(StaffTotp::PERIOD)->toBe(30);
    expect(StaffTotp::DIGEST)->toBe('sha1');
    expect(StaffTotp::SECRET_BYTES)->toBe(20);
    $totp = TOTP::createFromSecret(Base32::encodeUpper('12345678901234567890'), new StaffClock);
    $totp->setDigits(8);
    $totp->setDigest('sha1');
    $totp->setPeriod(30);
    expect($totp->at(59))->toBe('94287082');
    expect($totp->at(1111111109))->toBe('07081804');
    $generated = StaffTotp::generate();
    expect(strlen($generated->getSecret()))->toBe(32);
    expect($generated->getDigits())->toBe(6);
    expect($generated->getPeriod())->toBe(30);
    expect($generated->getDigest())->toBe('sha1');
});

it('IdentityTwoFactor requires owners and publishing staff to enroll before the workspace', function (): void {
    $owner = twoFactorOwner();
    $this->post('/admin/login', ['email' => $owner->email, 'password' => twoFactorPassword()])
        ->assertRedirect('/admin/two-factor');
    $this->get('/admin')->assertRedirect('/admin/two-factor');
    $this->getJson('/admin')->assertForbidden();
    $this->get('/admin/staff')->assertRedirect('/admin/two-factor');
    $this->get('/admin/two-factor')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('TwoFactor')->where('mode', 'enroll')->where('label', $owner->email)
        ->missing('staff.password')->has('secret')->has('uri'));
    $manager = twoFactorLimited('manager');
    $this->post('/admin/logout');
    $this->post('/admin/login', ['email' => $manager->email, 'password' => twoFactorPassword()])
        ->assertRedirect('/admin/two-factor');
    $fulfillment = twoFactorLimited('fulfillment');
    $this->post('/admin/logout');
    $this->post('/admin/login', ['email' => $fulfillment->email, 'password' => twoFactorPassword()])
        ->assertRedirect('/admin');
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('AdminHome'));
});

it('IdentityTwoFactor confirms encrypted enrollment and single-use recovery codes', function (): void {
    $owner = twoFactorOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/two-factor');
    $secret = app(ManageTwoFactor::class)->start($owner)->secret;
    expect(json_encode(app(ManageTwoFactor::class)->start($owner)))->not->toContain($secret);
    $row = DB::table('staff_two_factor')->where('staff_id', $owner->id)->first();
    expect($row->secret_payload)->toBeNull();
    expect($row->pending_payload)->not->toContain($secret);
    $this->from('/admin/two-factor')->post('/admin/two-factor', ['code' => '000000'])
        ->assertRedirect('/admin/two-factor')->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code');
    $this->post('/admin/two-factor', ['code' => StaffTotp::code($secret)])->assertRedirect('/admin/two-factor');
    $page = $this->get('/admin/two-factor')->assertOk()->viewData('page');
    expect($page['component'] ?? null)->toBe('TwoFactor');
    expect($page['props']['mode'] ?? null)->toBe('codes');
    expect($page['props']['secret'] ?? null)->toBeNull();
    $codes = $page['props']['recoveryCodes'] ?? [];
    expect($codes)->toHaveCount(10);
    $this->get('/admin/two-factor')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'reauthenticate'));
    $this->get('/admin')->assertOk();
    expect(DB::table('staff_two_factor')->where('staff_id', $owner->id)->value('secret_payload'))->not->toContain($secret);
    $this->travel(31)->seconds();
    $this->post('/admin/logout');
    $this->post('/admin/login', ['email' => $owner->email, 'password' => twoFactorPassword()])->assertRedirect('/admin/two-factor');
    $this->get('/admin/two-factor')->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'challenge')->where('secret', null));
    $this->post('/admin/two-factor/challenge', ['code' => StaffTotp::code($secret)])->assertRedirect('/admin');
    $this->post('/admin/two-factor/challenge', ['code' => StaffTotp::code($secret)])->assertForbidden();
    $this->post('/admin/logout');
    $this->post('/admin/login', ['email' => $owner->email, 'password' => twoFactorPassword()]);
    $this->post('/admin/two-factor/challenge', ['code' => $codes[0]])->assertRedirect('/admin/two-factor');
    $this->get('/admin/two-factor')->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'enroll'));
    $this->post('/admin/logout');
    $this->post('/admin/login', ['email' => $owner->email, 'password' => twoFactorPassword()]);
    $this->post('/admin/two-factor/challenge', ['code' => $codes[0]]);
    $this->get('/admin')->assertRedirect('/admin/two-factor');
    $this->get('/admin/two-factor')->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'enroll'));
});

it('IdentityTwoFactor rejects replayed TOTP, swapped ciphertext and expired pending enrollment', function (): void {
    $owner = twoFactorOwner();
    $other = twoFactorLimited('manager');
    $enrollment = app(ManageTwoFactor::class)->start($owner);
    app(ManageTwoFactor::class)->confirm($owner, StaffTotp::code($enrollment->secret));
    $this->travel(31)->seconds();
    $this->post('/admin/login', ['email' => $owner->email, 'password' => twoFactorPassword()]);
    $code = StaffTotp::code($enrollment->secret);
    $this->post('/admin/two-factor/challenge', ['code' => $code])->assertRedirect('/admin');
    $this->post('/admin/logout');
    $this->post('/admin/login', ['email' => $owner->email, 'password' => twoFactorPassword()]);
    $this->post('/admin/two-factor/challenge', ['code' => $code])->assertSessionHasErrors('code');
    $payload = DB::table('staff_two_factor')->where('staff_id', $owner->id)->value('secret_payload');
    DB::table('staff_two_factor')->insert([
        'staff_id' => $other->id, 'secret_payload' => $payload, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(fn () => app(ManageTwoFactor::class)->challenge($other, StaffTotp::code($enrollment->secret)))
        ->toThrow(RuntimeException::class);
    expect(Crypt::decryptString((string) $payload))->toContain($owner->id);
    $pendingStaff = twoFactorLimited('content_editor');
    $pendingStaff->forceFill(['role' => 'manager', 'permissions' => StaffPermissions::forRole('manager')])->save();
    $pending = app(ManageTwoFactor::class)->start($pendingStaff);
    $this->travel(16)->minutes();
    $this->actingAs($pendingStaff, 'staff')->withSession(['staff_session_version' => 1])
        ->post('/admin/two-factor', ['code' => StaffTotp::code($pending->secret)])
        ->assertSessionHasErrors('code');
});

it('IdentityTwoFactor requires fresh reauthentication for staff mutations and expires it', function (): void {
    $owner = twoFactorOwner();
    $enrollment = app(ManageTwoFactor::class)->start($owner);
    app(ManageTwoFactor::class)->confirm($owner, StaffTotp::code($enrollment->secret));
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1, 'staff_two_factor_verified' => true]);
    $this->patchJson('/admin/staff/'.$owner->id, ['access' => ['role' => 'owner', 'is_active' => true, 'version' => 1]])->assertForbidden();
    $this->postJson('/admin/staff/invitations', ['invitation' => ['email' => 'editor@example.test', 'role' => 'content_editor']])->assertForbidden();
    $this->from('/admin/staff')->patch('/admin/staff/'.$owner->id, ['access' => ['role' => 'owner', 'is_active' => true, 'version' => 1]])
        ->assertRedirect('/admin/two-factor');
    $this->travel(31)->seconds();
    $this->post('/admin/two-factor/reauthenticate', [
        'password' => twoFactorPassword(), 'code' => StaffTotp::code($enrollment->secret),
    ])->assertRedirect('/admin/staff');
    $editor = twoFactorLimited('content_editor');
    $this->patch('/admin/staff/'.$editor->id, ['access' => ['role' => 'fulfillment', 'is_active' => true, 'version' => 1]])
        ->assertRedirect('/admin/staff');
    expect($editor->fresh()->role)->toBe('fulfillment');
    $this->travel(16)->minutes();
    $this->patchJson('/admin/staff/'.$editor->id, ['access' => ['role' => 'content_editor', 'is_active' => true, 'version' => 2]])->assertForbidden();
});

it('IdentityTwoFactor keeps invitation secrets and codes out of page history and rejects forged requests', function (): void {
    $owner = twoFactorOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/two-factor');
    $secret = app(ManageTwoFactor::class)->start($owner)->secret;
    $this->post('/admin/two-factor', ['code' => StaffTotp::code($secret)]);
    $this->get('/admin/two-factor');
    expect(session()->get('staff_recovery_codes'))->toBeNull();
    $invitation = app(InviteStaff::class)->invite($owner, ['email' => 'editor@example.test', 'role' => 'content_editor']);
    expect($invitation->token)->not->toBeEmpty();
    $this->app->instance('env', 'local');
    $this->postJson('/admin/two-factor', ['code' => '123456'])->assertStatus(419);
    $this->postJson('/admin/two-factor/challenge', ['code' => '123456'])->assertStatus(419);
    $this->postJson('/admin/two-factor/reauthenticate', ['password' => twoFactorPassword(), 'code' => '123456'])->assertStatus(419);
});
