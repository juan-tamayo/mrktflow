<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\ProveCustomerIdentity;
use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\CustomerRegistration;

beforeEach(function (): void {
    DB::table('installations')->where('id', 1)->update(['status' => 'complete', 'completed_at' => now()]);
});

it('IdentityCustomer creates an unverified customer with hashed private credentials and minimal audit', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    expect($customer)->toBeInstanceOf(CustomerAccount::class);
    expect($customer->email)->toBe('shopper@example.test');
    expect($customer->email_verified_at)->toBeNull();
    expect($customer->session_version)->toBe(1);
    expect(Hash::check(CustomerRegistration::input()['password'], $customer->password))->toBeTrue();
    expect(json_encode($customer))->not->toContain($customer->password)->not->toContain('session_version');
    expect(DB::table('staff_accounts')->count())->toBe(0);
    expect(DB::table('outbox_events')->where('intent_key', 'customer:registered:'.$customer->id)->count())->toBe(1);
});

it('IdentityCustomer rejects weak or truncated credentials and injected verification or privileges', function (): void {
    foreach ([['password' => 'short', 'password_confirmation' => 'short'], ['password' => str_repeat('é', 37), 'password_confirmation' => str_repeat('é', 37)], ['password_confirmation' => 'Wrong confirmation'], ['role' => 'owner'], ['permissions' => ['staff.manage']], ['email_verified_at' => now()->toIso8601String()], ['is_active' => false], ['session_version' => 99]] as $override) {
        expect(fn () => app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), $override)))->toThrow(ValidationException::class);
    }
    expect(DB::table('customer_accounts')->count())->toBe(0);
    expect(DB::table('outbox_events')->count())->toBe(0);
});

it('IdentityCustomer keeps a canonical unique email and leaves no partial duplicate registration', function (): void {
    app(RegisterCustomer::class)->register(CustomerRegistration::input());
    expect(fn () => app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), ['email' => 'shopper@example.test'])))->toThrow(ValidationException::class);
    expect(DB::table('customer_accounts')->count())->toBe(1);
    expect(DB::table('outbox_events')->count())->toBe(1);
});

it('IdentityCustomer refuses registration before permanent installation completion', function (): void {
    foreach (['unconfigured', 'pending_owner'] as $status) {
        DB::table('installations')->where('id', 1)->update(['status' => $status]);
        expect(fn () => app(RegisterCustomer::class)->register(CustomerRegistration::input()))->toThrow(HttpException::class);
    }
    expect(DB::table('customer_accounts')->count())->toBe(0);
});

it('IdentityCustomer uses separate providers and never inherits matching staff privileges', function (): void {
    $staff = new StaffAccount;
    $staff->forceFill(['name' => 'Fictional Owner', 'email' => 'shopper@example.test', 'password' => Hash::make('Different staff password 471!'), 'role' => 'owner', 'permissions' => StaffPermissions::forRole('owner'), 'is_active' => true])->save();
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    expect(Auth::guard('customer')->attempt(['email' => $customer->email, 'password' => CustomerRegistration::input()['password'], 'is_active' => true]))->toBeTrue();
    expect(Auth::guard('customer')->user())->toBeInstanceOf(CustomerAccount::class);
    expect(Auth::guard('staff')->check())->toBeFalse();
    foreach (StaffPermissions::all() as $ability) {
        expect(Gate::forUser($customer)->allows($ability))->toBeFalse();
    }
    expect(Auth::guard('staff')->attempt(['email' => $staff->email, 'password' => CustomerRegistration::input()['password']]))->toBeFalse();
});

it('IdentityCustomer requires a transaction and current verified identity before guest ownership proof', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    DB::transaction(function () use ($customer): void {
        expect(fn () => app(ProveCustomerIdentity::class)->prove($customer))->toThrow(AuthorizationException::class);
        $customer->forceFill(['email_verified_at' => now()])->save();
        $proof = app(ProveCustomerIdentity::class)->prove($customer);
        expect($proof->customerId)->toBe($customer->id);
        expect($proof->email)->toBe('shopper@example.test');
        DB::table('customer_accounts')->where('id', $customer->id)->update(['session_version' => 2]);
        expect(fn () => app(ProveCustomerIdentity::class)->prove($customer))->toThrow(AuthorizationException::class);
    });
});

it('IdentityCustomer denies disabled, removed, and email-changed stale principals', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $customer->forceFill(['email_verified_at' => now()])->save();
    DB::transaction(function () use ($customer): void {
        DB::table('customer_accounts')->where('id', $customer->id)->update(['email' => 'changed@example.test']);
        expect(fn () => app(ProveCustomerIdentity::class)->prove($customer))->toThrow(AuthorizationException::class);
        DB::table('customer_accounts')->where('id', $customer->id)->update(['email' => $customer->email, 'is_active' => false]);
        expect(fn () => app(ProveCustomerIdentity::class)->prove($customer))->toThrow(AuthorizationException::class);
        DB::table('customer_accounts')->where('id', $customer->id)->delete();
        expect(fn () => app(ProveCustomerIdentity::class)->prove($customer))->toThrow(AuthorizationException::class);
    });
});

it('IdentityCustomer rolls registration back if durable audit intent cannot be recorded', function (): void {
    config(['outbox.consumers' => ['platform.audit.recorded' => []]]);
    expect(fn () => app(RegisterCustomer::class)->register(CustomerRegistration::input()))->toThrow(InvalidArgumentException::class);
    expect(DB::table('customer_accounts')->count())->toBe(0);
    expect(DB::table('outbox_events')->count())->toBe(0);
});
