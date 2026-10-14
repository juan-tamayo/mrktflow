<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\IssueCustomerChallenge;
use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Actions\ResetCustomerPassword;
use App\Domain\Identity\Actions\VerifyCustomerEmail;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\CustomerRegistration;

beforeEach(function (): void {
    $this->freezeTime();
    DB::table('installations')->where('id', 1)->update(['status' => 'complete', 'completed_at' => now()]);
});

function customerResetInput(): array
{
    return ['password' => 'Fictional replacement passphrase 872!', 'password_confirmation' => 'Fictional replacement passphrase 872!'];
}

it('IdentityCustomer issues private purpose-bound challenge digests with explicit expiry', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $issuer = app(IssueCustomerChallenge::class);
    $verify = $issuer->verification($customer);
    $reset = $issuer->passwordReset('SHOPPER@example.test');
    expect($verify)->not->toBeNull();
    expect($reset)->not->toBeNull();
    $row = DB::table('customer_challenges')->where('id', $verify->id)->first();
    expect($row->purpose)->toBe('verify_email');
    expect($row->token_digest)->toBe(hash('sha256', $verify->token()));
    expect($row->email_digest)->toBe(hash('sha256', $customer->email));
    expect($row->session_version)->toBe(1);
    expect($verify->expiresAt->equalTo(now()->addDay()))->toBeTrue();
    expect($reset->expiresAt->equalTo(now()->addHour()))->toBeTrue();
    expect(json_encode($verify))->not->toContain($verify->token());
    expect(json_encode($row))->not->toContain($verify->token());
    expect(fn () => serialize($verify))->toThrow(LogicException::class);
    expect(DB::table('customer_challenges')->count())->toBe(2);
});

it('IdentityCustomer verifies only the authenticated matching account and consumes the token once', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $other = app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), ['email' => 'other@example.test']));
    $challenge = app(IssueCustomerChallenge::class)->verification($customer);
    expect(fn () => app(VerifyCustomerEmail::class)->verify($other, $challenge->token()))->toThrow(HttpException::class);
    expect($customer->fresh()->email_verified_at)->toBeNull();
    $verified = app(VerifyCustomerEmail::class)->verify($customer, $challenge->token());
    expect($verified->email_verified_at)->not->toBeNull();
    expect($verified->session_version)->toBe(2);
    expect(fn () => app(VerifyCustomerEmail::class)->verify($verified, $challenge->token()))->toThrow(HttpException::class);
    expect(DB::table('customer_challenges')->where('id', $challenge->id)->first())->token_digest->toBeNull()->consumed_at->not->toBeNull();
});

it('IdentityCustomer resets a password without granting email verification or modifying staff', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $staff = new StaffAccount;
    $staff->forceFill(['name' => 'Fictional Owner', 'email' => $customer->email, 'password' => Hash::make('Unchanged staff passphrase 471!'), 'role' => 'owner', 'permissions' => StaffPermissions::forRole('owner'), 'is_active' => true])->save();
    $staffPassword = $staff->password;
    $reset = app(IssueCustomerChallenge::class)->passwordReset($customer->email);
    $changed = app(ResetCustomerPassword::class)->reset($reset->token(), customerResetInput());
    expect(Hash::check(customerResetInput()['password'], $changed->password))->toBeTrue();
    expect(Hash::check(CustomerRegistration::input()['password'], $changed->password))->toBeFalse();
    expect($changed->session_version)->toBe(2);
    expect($changed->email_verified_at)->toBeNull();
    expect($changed->remember_token)->toBeNull();
    expect($staff->fresh()->password)->toBe($staffPassword);
    expect($staff->fresh()->session_version)->toBe(1);
    expect(fn () => app(ResetCustomerPassword::class)->reset($reset->token(), customerResetInput()))->toThrow(HttpException::class);
});

it('IdentityCustomer rejects expired wrong-purpose malformed and superseded challenges', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $issuer = app(IssueCustomerChallenge::class);
    $verify = $issuer->verification($customer);
    expect(fn () => app(ResetCustomerPassword::class)->reset($verify->token(), customerResetInput()))->toThrow(HttpException::class);
    $old = $issuer->passwordReset($customer->email);
    $new = $issuer->passwordReset($customer->email);
    expect(fn () => app(ResetCustomerPassword::class)->reset($old->token(), customerResetInput()))->toThrow(HttpException::class);
    foreach (['invalid', str_repeat('0', 64), strtoupper($new->token())] as $token) {
        expect(fn () => app(ResetCustomerPassword::class)->reset($token, customerResetInput()))->toThrow(HttpException::class);
    }
    $this->travel(61)->minutes();
    expect(fn () => app(ResetCustomerPassword::class)->reset($new->token(), customerResetInput()))->toThrow(HttpException::class);
    expect($customer->fresh()->session_version)->toBe(1);
    $this->travel(24)->hours();
    expect(fn () => app(VerifyCustomerEmail::class)->verify($customer, $verify->token()))->toThrow(HttpException::class);
});

it('IdentityCustomer invalidates both challenge purposes after a password or verification change', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $issuer = app(IssueCustomerChallenge::class);
    $verify = $issuer->verification($customer);
    $reset = $issuer->passwordReset($customer->email);
    $verified = app(VerifyCustomerEmail::class)->verify($customer, $verify->token());
    expect(fn () => app(ResetCustomerPassword::class)->reset($reset->token(), customerResetInput()))->toThrow(HttpException::class);
    expect($issuer->verification($verified))->toBeNull();
    $reset = $issuer->passwordReset($verified->email);
    $changed = app(ResetCustomerPassword::class)->reset($reset->token(), customerResetInput());
    expect($changed->email_verified_at)->not->toBeNull();
    expect($changed->session_version)->toBe(3);
    expect(DB::table('customer_challenges')->whereNotNull('token_digest')->count())->toBe(0);
});

it('IdentityCustomer rejects changed-email disabled and stale account-bound credentials', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $issuer = app(IssueCustomerChallenge::class);
    $verify = $issuer->verification($customer);
    $reset = $issuer->passwordReset($customer->email);
    foreach ([['email' => 'changed@example.test'], ['email' => $customer->email, 'is_active' => false], ['is_active' => true, 'session_version' => 2]] as $change) {
        DB::table('customer_accounts')->where('id', $customer->id)->update($change);
        expect(fn () => app(VerifyCustomerEmail::class)->verify($customer, $verify->token()))->toThrow(HttpException::class);
        expect(fn () => app(ResetCustomerPassword::class)->reset($reset->token(), customerResetInput()))->toThrow(HttpException::class);
        expect(fn () => $issuer->verification($customer))->toThrow(AuthorizationException::class);
    }
});

it('IdentityCustomer does not create recovery secrets for unknown inactive or staff-only emails', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $customer->is_active = false;
    $customer->save();
    expect(app(IssueCustomerChallenge::class)->passwordReset($customer->email))->toBeNull();
    $staff = new StaffAccount;
    $staff->forceFill(['name' => 'Fictional Staff', 'email' => 'staff-only@example.test', 'password' => Hash::make('Fictional staff passphrase 471!'), 'role' => 'owner', 'permissions' => StaffPermissions::forRole('owner'), 'is_active' => true])->save();
    expect(app(IssueCustomerChallenge::class)->passwordReset('staff-only@example.test'))->toBeNull();
    expect(app(IssueCustomerChallenge::class)->passwordReset('unknown@example.test'))->toBeNull();
    expect(DB::table('customer_challenges')->count())->toBe(0);
});

it('IdentityCustomer rejects reset input injection weak passwords and byte truncation without consuming the link', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $reset = app(IssueCustomerChallenge::class)->passwordReset($customer->email);
    foreach ([['password' => 'short', 'password_confirmation' => 'short'], ['password' => str_repeat('é', 37), 'password_confirmation' => str_repeat('é', 37)], ['password_confirmation' => 'Wrong confirmation'], ['email' => 'injected@example.test'], ['role' => 'owner'], ['email_verified_at' => now()->toIso8601String()]] as $override) {
        expect(fn () => app(ResetCustomerPassword::class)->reset($reset->token(), array_replace(customerResetInput(), $override)))->toThrow(ValidationException::class);
    }
    expect($customer->fresh()->session_version)->toBe(1);
    expect(DB::table('customer_challenges')->where('id', $reset->id)->value('token_digest'))->not->toBeNull();
});

it('IdentityCustomer rolls back verification or password changes when their audit intent fails', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $verify = app(IssueCustomerChallenge::class)->verification($customer);
    $reset = app(IssueCustomerChallenge::class)->passwordReset($customer->email);
    config(['outbox.consumers' => ['platform.audit.recorded' => []]]);
    expect(fn () => app(VerifyCustomerEmail::class)->verify($customer, $verify->token()))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(ResetCustomerPassword::class)->reset($reset->token(), customerResetInput()))->toThrow(InvalidArgumentException::class);
    expect($customer->fresh()->email_verified_at)->toBeNull();
    expect($customer->fresh()->session_version)->toBe(1);
    expect(Hash::check(CustomerRegistration::input()['password'], $customer->fresh()->password))->toBeTrue();
    expect(DB::table('customer_challenges')->whereNotNull('token_digest')->count())->toBe(2);
});
