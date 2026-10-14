<?php

declare(strict_types=1);

use App\Application\Setup\CreateInitialOwner;
use App\Domain\Identity\Actions\AcceptStaffInvitation;
use App\Domain\Identity\Actions\ChangeStaffAccess;
use App\Domain\Identity\Actions\InviteStaff;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Platform\Actions\InstallStore;
use App\Domain\Platform\Actions\SetupAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function pendingOwnerGrant(): array
{
    $access = app(SetupAccess::class);
    $grant = $access->unlock($access->issueSecret());
    app(InstallStore::class)->save($grant, json_decode(file_get_contents(base_path('tests/Fixtures/store-settings.json')), true, flags: JSON_THROW_ON_ERROR), 0);

    return $grant;
}

function ownerFixtureInput(): array
{
    return ['name' => 'Fictional Owner', 'email' => 'OWNER@example.test', 'password' => 'Fictional owner passphrase 471!', 'password_confirmation' => 'Fictional owner passphrase 471!'];
}

it('IdentityStaff creates the initial owner atomically and permanently closes setup', function (): void {
    $grant = pendingOwnerGrant();
    $owner = app(CreateInitialOwner::class)->create($grant, ownerFixtureInput());
    expect($owner)->toBeInstanceOf(StaffAccount::class);
    expect($owner->email)->toBe('owner@example.test');
    expect($owner->hasPermission('staff.manage'))->toBeTrue();
    expect(Hash::check(ownerFixtureInput()['password'], $owner->password))->toBeTrue();
    expect(json_encode($owner))->not->toContain($owner->password);
    expect(DB::table('installations')->first())->status->toBe('complete')->secret_digest->toBeNull()->generation->toBeNull()->completed_at->not->toBeNull();
    expect(fn () => app(CreateInitialOwner::class)->create($grant, ownerFixtureInput()))->toThrow(HttpException::class);
    expect(fn () => app(SetupAccess::class)->issueSecret())->toThrow(HttpException::class);
    expect(DB::table('staff_accounts')->count())->toBe(1);
});

it('IdentityStaff refuses initial owner creation without saved settings or a live setup grant', function (): void {
    $access = app(SetupAccess::class);
    $grant = $access->unlock($access->issueSecret());
    expect(fn () => app(CreateInitialOwner::class)->create($grant, ownerFixtureInput()))->toThrow(HttpException::class);
    expect(DB::table('staff_accounts')->count())->toBe(0);
    $grant = pendingOwnerGrant();
    $this->travel(16)->minutes();
    expect(fn () => app(CreateInitialOwner::class)->create($grant, ownerFixtureInput()))->toThrow(HttpException::class);
    expect(DB::table('staff_accounts')->count())->toBe(0);
});

it('IdentityStaff rolls back install completion for weak passwords and injected permissions', function (): void {
    $grant = pendingOwnerGrant();
    foreach ([['password' => 'short', 'password_confirmation' => 'short'], ['role' => 'owner'], ['permissions' => ['staff.manage']]] as $override) {
        expect(fn () => app(CreateInitialOwner::class)->create($grant, array_replace(ownerFixtureInput(), $override)))->toThrow(ValidationException::class);
        expect(DB::table('installations')->value('status'))->toBe('pending_owner');
        expect(DB::table('staff_accounts')->count())->toBe(0);
    }
});

it('IdentityStaff refuses password truncation and confirmation mismatch without closing setup', function (): void {
    $grant = pendingOwnerGrant();
    foreach ([str_repeat('é', 37), 'Fictional replacement passphrase'] as $password) {
        $input = array_replace(ownerFixtureInput(), ['password' => $password]);
        if (strlen($password) > 72) {
            $input['password_confirmation'] = $password;
        }
        expect(fn () => app(CreateInitialOwner::class)->create($grant, $input))->toThrow(ValidationException::class);
        expect(DB::table('installations')->value('status'))->toBe('pending_owner');
        expect(DB::table('staff_accounts')->count())->toBe(0);
    }
});

it('IdentityStaff enforces server permission presets and denies foreign principals', function (): void {
    $owner = app(CreateInitialOwner::class)->create(pendingOwnerGrant(), ownerFixtureInput());
    $gate = app(Gate::class);
    expect($gate->forUser($owner)->allows('staff.manage'))->toBeTrue();
    foreach (['manager', 'content_editor', 'fulfillment'] as $role) {
        $staff = new StaffAccount;
        $staff->forceFill(['is_active' => true, 'role' => $role, 'permissions' => StaffPermissions::forRole($role)]);
        expect($gate->forUser($staff)->allows('admin.access'))->toBeTrue();
        expect($gate->forUser($staff)->allows('staff.manage'))->toBeFalse();
        expect($gate->forUser($staff)->allows('integrations.manage'))->toBeFalse();
        expect($gate->forUser($staff)->allows('content.publish'))->toBe($role === 'manager');
        expect($gate->forUser($staff)->allows('orders.refund'))->toBeFalse();
        $staff->is_active = false;
        expect($gate->forUser($staff)->allows('admin.access'))->toBeFalse();
    }
    $foreign = new User;
    $foreign->forceFill(['email' => $owner->email, 'role' => 'owner', 'permissions' => ['staff.manage']]);
    expect($gate->forUser($foreign)->allows('staff.manage'))->toBeFalse();
    expect($gate->forUser($owner)->allows('unregistered.power'))->toBeFalse();
});

it('IdentityStaff protects the final owner and invalidates sessions when privileges change', function (): void {
    $owner = app(CreateInitialOwner::class)->create(pendingOwnerGrant(), ownerFixtureInput());
    $change = app(ChangeStaffAccess::class);
    foreach ([['role' => 'manager', 'is_active' => true], ['role' => 'owner', 'is_active' => false]] as $input) {
        expect(fn () => $change->change($owner, $owner->id, $input + ['version' => 1]))->toThrow(HttpException::class);
        expect($owner->fresh()->role)->toBe('owner');
        expect($owner->fresh()->is_active)->toBeTrue();
        expect($owner->fresh()->session_version)->toBe(1);
    }
    $replacement = new StaffAccount;
    $replacement->forceFill([
        'name' => 'Fictional Replacement', 'email' => 'replacement@example.test', 'password' => Hash::make(ownerFixtureInput()['password']),
        'role' => 'owner', 'permissions' => StaffPermissions::forRole('owner'), 'is_active' => true,
    ])->save();
    $changed = $change->change($owner, $owner->id, ['role' => 'manager', 'is_active' => true, 'version' => 1]);
    expect($changed->role)->toBe('manager');
    expect($changed->hasPermission('staff.manage'))->toBeFalse();
    expect($changed->session_version)->toBe(2);
    expect(fn () => $change->change($owner, $replacement->id, ['role' => 'manager', 'is_active' => true, 'version' => 1]))->toThrow(AuthorizationException::class);
    expect($replacement->fresh()->role)->toBe('owner');
});

it('IdentityStaff binds single-use invitations to a server-owned email and permission preset', function (): void {
    $owner = app(CreateInitialOwner::class)->create(pendingOwnerGrant(), ownerFixtureInput());
    $invitation = app(InviteStaff::class)->invite($owner, ['email' => 'EDITOR@example.test', 'role' => 'content_editor']);
    $stored = DB::table('staff_invitations')->where('id', $invitation->id)->first();
    expect($stored->token_digest)->toBe(hash('sha256', $invitation->token));
    expect(json_encode($stored))->not->toContain($invitation->token);
    expect(json_encode($invitation))->not->toContain($invitation->token);
    $input = ownerFixtureInput();
    unset($input['email']);
    expect(fn () => app(AcceptStaffInvitation::class)->accept($invitation->token, $input + ['role' => 'owner']))->toThrow(ValidationException::class);
    $staff = app(AcceptStaffInvitation::class)->accept($invitation->token, $input);
    expect($staff->email)->toBe('editor@example.test');
    expect($staff->role)->toBe('content_editor');
    expect($staff->hasPermission('content.write'))->toBeTrue();
    expect($staff->hasPermission('staff.manage'))->toBeFalse();
    expect(fn () => app(AcceptStaffInvitation::class)->accept($invitation->token, $input))->toThrow(HttpException::class);
    expect(DB::table('staff_accounts')->count())->toBe(2);
});

it('IdentityStaff rejects expired and superseded invitations and revoked inviters', function (): void {
    $owner = app(CreateInitialOwner::class)->create(pendingOwnerGrant(), ownerFixtureInput());
    $inviter = app(InviteStaff::class);
    $first = $inviter->invite($owner, ['email' => 'staff@example.test', 'role' => 'fulfillment']);
    $second = $inviter->invite($owner, ['email' => 'STAFF@example.test', 'role' => 'content_editor']);
    $input = ownerFixtureInput();
    unset($input['email']);
    $accept = app(AcceptStaffInvitation::class);
    expect(fn () => $accept->accept($first->token, $input))->toThrow(HttpException::class);
    $this->travel(49)->hours();
    expect(fn () => $accept->accept($second->token, $input))->toThrow(HttpException::class);
    $this->travelBack();
    $third = $inviter->invite($owner, ['email' => 'another@example.test', 'role' => 'manager']);
    DB::table('staff_accounts')->where('id', $owner->id)->update(['is_active' => false]);
    expect(fn () => $accept->accept($third->token, $input))->toThrow(HttpException::class);
    expect(fn () => $inviter->invite($owner, ['email' => 'other@example.test', 'role' => 'owner']))->toThrow(AuthorizationException::class);
    expect(DB::table('staff_accounts')->count())->toBe(1);
});
