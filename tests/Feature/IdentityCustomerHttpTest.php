<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Fixtures\CustomerRegistration;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    DB::table('installations')->where('id', 1)->update(['status' => 'complete', 'completed_at' => now()]);
});

it('IdentityCustomer HTTP renders server forms and hides them before installation', function (): void {
    $this->get('/account/register')->assertOk()->assertSee('Create your account')->assertSee('name="customer[email]"', false);
    $this->get('/account/login')->assertOk()->assertSee('Sign in to your account');
    DB::table('installations')->where('id', 1)->update(['status' => 'pending_owner']);
    foreach (['/account/register', '/account/login', '/account'] as $path) {
        $this->get($path)->assertNotFound();
    }
    $this->postJson('/account/register', ['customer' => CustomerRegistration::input()])->assertNotFound();
    $this->postJson('/account/login', ['email' => 'shopper@example.test', 'password' => CustomerRegistration::input()['password']])->assertNotFound();
});

it('IdentityCustomer HTTP registers and rotates into a private customer account without staff access', function (): void {
    $this->withSession(['guest_identity' => 'synthetic-current-guest'])->get('/account/register');
    $oldId = session()->getId();
    $this->post('/account/register', ['customer' => CustomerRegistration::input()])->assertRedirect('/account');
    expect(session()->getId())->not->toBe($oldId);
    expect(session('guest_identity'))->toBe('synthetic-current-guest');
    $this->assertAuthenticated('customer');
    $this->assertGuest('staff');
    $this->get('/account')->assertOk()->assertSee('Fictional Customer')->assertSee('shopper@example.test')->assertSee('Email not verified')
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->getJson('/admin')->assertUnauthorized();
});

it('IdentityCustomer HTTP rejects injected privileges and never flashes nested passwords', function (): void {
    $input = CustomerRegistration::input() + ['role' => 'owner', 'email_verified_at' => now()->toIso8601String()];
    $this->from('/account/register')->post('/account/register', ['customer' => $input])
        ->assertRedirect('/account/register')->assertSessionHasErrors('customer')->assertSessionMissing('_old_input.customer');
    expect(CustomerAccount::query()->count())->toBe(0);
    $input = array_replace(CustomerRegistration::input(), ['password_confirmation' => 'PRIVATE_CONFIRMATION_SENTINEL']);
    $this->from('/account/register')->post('/account/register', ['customer' => $input])
        ->assertRedirect('/account/register')->assertSessionHasErrors('customer.password')->assertSessionMissing('_old_input.customer');
    $this->get('/account/register')->assertDontSee('PRIVATE_CONFIRMATION_SENTINEL')->assertDontSee($input['password']);
});

it('IdentityCustomer HTTP preserves password whitespace and canonicalizes login email', function (): void {
    $input = array_replace(CustomerRegistration::input(), ['password' => '  Fictional spaced passphrase 471!  ', 'password_confirmation' => '  Fictional spaced passphrase 471!  ']);
    $this->post('/account/register', ['customer' => $input])->assertRedirect('/account');
    $customer = CustomerAccount::query()->firstOrFail();
    expect(Hash::check($input['password'], $customer->password))->toBeTrue();
    $this->post('/account/logout')->assertRedirect('/account/login')->assertSessionMissing('customer_session_version');
    $this->assertGuest('customer');
    $this->post('/account/login', ['email' => 'SHOPPER@example.test', 'password' => $input['password']])->assertRedirect('/account');
    $this->assertAuthenticated('customer');
});

it('IdentityCustomer HTTP returns identical failed-login errors for unknown inactive and wrong-password accounts', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $unknown = $this->postJson('/account/login', ['email' => 'unknown@example.test', 'password' => 'Incorrect fictional passphrase'])->assertUnprocessable()->json('errors.email');
    $wrong = $this->postJson('/account/login', ['email' => $customer->email, 'password' => 'Incorrect fictional passphrase'])->assertUnprocessable()->json('errors.email');
    $customer->is_active = false;
    $customer->save();
    $inactive = $this->postJson('/account/login', ['email' => $customer->email, 'password' => CustomerRegistration::input()['password']])->assertUnprocessable()->json('errors.email');
    expect($wrong)->toBe($unknown)->toBe($inactive);
    $this->assertGuest('customer');
});

it('IdentityCustomer HTTP rejects stale sessions and foreign principals on account routes', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])->get('/account')->assertOk();
    DB::table('customer_accounts')->where('id', $customer->id)->update(['session_version' => 2]);
    $this->get('/account')->assertRedirect('/account/login')->assertSessionMissing('customer_session_version');
    $foreign = new StaffAccount;
    $foreign->forceFill(['id' => $customer->id, 'session_version' => 2]);
    $this->actingAs($foreign, 'customer')->withSession(['customer_session_version' => 2])->getJson('/account')->assertUnauthorized();
});

it('IdentityCustomer HTTP requires sign-out before switching or registering another customer', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/login')->assertRedirect('/account');
    $this->get('/account/register')->assertRedirect('/account');
    $this->post('/account/register', ['customer' => array_replace(CustomerRegistration::input(), ['email' => 'second@example.test'])])->assertRedirect('/account');
    expect(CustomerAccount::query()->count())->toBe(1);
    expect(Auth::guard('customer')->id())->toBe($customer->id);
});

it('IdentityCustomer HTTP does not authenticate staff when a shopper registers a matching staff email', function (): void {
    $staff = new StaffAccount;
    $staff->forceFill(['name' => 'Fictional Owner', 'email' => 'shopper@example.test', 'password' => Hash::make('Different staff passphrase 471!'), 'role' => 'owner', 'permissions' => StaffPermissions::forRole('owner'), 'is_active' => true])->save();
    $this->post('/account/register', ['customer' => CustomerRegistration::input()])->assertRedirect('/account');
    $this->getJson('/admin/staff')->assertUnauthorized();
    $this->assertGuest('staff');
    $this->assertAuthenticated('customer');
});

it('IdentityCustomer HTTP escapes profile data in server-rendered HTML', function (): void {
    $customer = app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), ['name' => '<img src=x onerror=alert(1)>']));
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])->get('/account')
        ->assertOk()->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)->assertDontSee('<img src=x', false)->assertDontSee($customer->password);
});

it('IdentityCustomer HTTP limits repeated login attempts across canonical email casing', function (): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/account/login', ['email' => $attempt % 2 ? 'SHOPPER@example.test' : 'shopper@example.test', 'password' => 'Incorrect fictional passphrase'])->assertUnprocessable();
    }
    $this->postJson('/account/login', ['email' => 'SHOPPER@example.test', 'password' => 'Incorrect fictional passphrase'])->assertTooManyRequests();
});

it('IdentityCustomer HTTP never flashes a failed sign-in password', function (): void {
    $this->from('/account/login')->post('/account/login', ['email' => 'unknown@example.test', 'password' => 'PRIVATE_LOGIN_SENTINEL_471!'])
        ->assertRedirect('/account/login')->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');
    $this->get('/account/login')->assertDontSee('PRIVATE_LOGIN_SENTINEL_471!');
});

it('IdentityCustomer HTTP bounds registration attempts without sharing staff or login counters', function (): void {
    $invalid = array_replace(CustomerRegistration::input(), ['password' => 'short', 'password_confirmation' => 'short']);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/account/register', ['customer' => $invalid])->assertUnprocessable();
    }
    $this->postJson('/account/register', ['customer' => CustomerRegistration::input()])->assertTooManyRequests();
    expect(CustomerAccount::query()->count())->toBe(0);
    $this->postJson('/account/login', ['email' => 'unknown@example.test', 'password' => 'Incorrect fictional passphrase'])->assertUnprocessable();
});
