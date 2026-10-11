<?php

declare(strict_types=1);

use App\Application\Identity\RequestCustomerChallengeMail;
use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Models\CustomerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Fixtures\CustomerRegistration;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    DB::table('installations')->where('id', 1)->update(['status' => 'complete', 'completed_at' => now()]);
});

function recoveryHttpToken(string $purpose): string
{
    $delivery = DB::table('customer_challenge_deliveries')->join('customer_challenges', 'customer_challenges.id', '=', 'customer_challenge_deliveries.challenge_id')
        ->where('purpose', $purpose)->orderByDesc('customer_challenge_deliveries.created_at')->first();

    return json_decode(Crypt::decryptString($delivery->payload), true, flags: JSON_THROW_ON_ERROR)['token'];
}

it('IdentityCustomer recovery HTTP queues signup verification atomically without reflecting the token', function (): void {
    $response = $this->post('/account/register', ['customer' => CustomerRegistration::input()])->assertRedirect('/account');
    expect(DB::table('customer_challenge_deliveries')->count())->toBe(1);
    $token = recoveryHttpToken('verify_email');
    $response->assertDontSee($token)->assertSessionMissing('_old_input.challenge_token');
    $this->get('/account')->assertSee('Confirm your email')->assertDontSee($token);
});

it('IdentityCustomer recovery HTTP GET never consumes or echoes a credential', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->verification($customer);
    $token = recoveryHttpToken('verify_email');
    foreach (['/account/verify', '/account/reset-password'] as $path) {
        $this->get($path.'?challenge_token='.$token)->assertOk()->assertDontSee($token)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
    }
    expect($customer->fresh()->email_verified_at)->toBeNull();
    expect(DB::table('customer_challenges')->whereNotNull('consumed_at')->count())->toBe(0);
});

it('IdentityCustomer recovery HTTP requires the bound customer and rotates only the verifying session', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->verification($customer);
    $token = recoveryHttpToken('verify_email');
    $this->post('/account/verify', ['challenge_token' => $token])->assertRedirect('/account/login');
    $other = app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), ['email' => 'other@example.test']));
    $this->actingAs($other, 'customer')->withSession(['customer_session_version' => 1])->post('/account/verify', ['challenge_token' => $token])->assertNotFound();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $oldId = session()->getId();
    $this->post('/account/verify', ['challenge_token' => $token])->assertRedirect('/account');
    expect(session()->getId())->not->toBe($oldId);
    expect(session('customer_session_version'))->toBe(2);
    expect($customer->fresh()->email_verified_at)->not->toBeNull();
    $this->get('/account')->assertSee('Email verified');
    $this->post('/account/verify', ['challenge_token' => $token])->assertNotFound();
    $this->assertGuest('staff');
});

it('IdentityCustomer recovery HTTP returns the same generic recovery result for known unknown and inactive email', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $known = $this->post('/account/forgot-password', ['email' => $customer->email])->assertRedirect('/account/forgot-password')->assertSessionHas('status');
    expect(DB::table('customer_challenge_deliveries')->count())->toBe(1);
    $message = session('status');
    $this->post('/account/forgot-password', ['email' => 'unknown@example.test'])->assertRedirect('/account/forgot-password')->assertSessionHas('status', $message);
    $customer->is_active = false;
    $customer->save();
    $this->post('/account/forgot-password', ['email' => $customer->email])->assertRedirect('/account/forgot-password')->assertSessionHas('status', $message);
    expect(DB::table('customer_challenge_deliveries')->count())->toBe(1);
    $known->assertDontSee(recoveryHttpToken('reset_password'));
});

it('IdentityCustomer recovery HTTP resets a bound password without signing in and invalidates prior sessions', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RequestCustomerChallengeMail::class)->passwordReset($customer->email);
    $token = recoveryHttpToken('reset_password');
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $password = '  Changed fictional passphrase 471!  ';
    $input = ['challenge_token' => $token, 'password_reset' => ['password' => $password, 'password_confirmation' => $password]];
    $this->post('/account/reset-password', $input)->assertRedirect('/account/login')->assertSessionMissing('customer_session_version');
    $this->assertGuest('customer');
    $this->assertGuest('staff');
    expect(Hash::check($password, $customer->fresh()->password))->toBeTrue();
    expect($customer->fresh()->session_version)->toBe(2);
    expect($customer->fresh()->email_verified_at)->toBeNull();
    $this->post('/account/reset-password', $input)->assertNotFound();
});

it('IdentityCustomer recovery HTTP never flashes codes or nested reset passwords on validation failure', function (): void {
    $this->from('/account/reset-password')->post('/account/reset-password', ['challenge_token' => str_repeat('b', 64), 'password_reset' => ['password' => 'PRIVATE_RESET_SENTINEL', 'password_confirmation' => 'wrong']])
        ->assertRedirect('/account/reset-password')->assertSessionHasErrors('password_reset.password')->assertSessionMissing('_old_input.challenge_token')->assertSessionMissing('_old_input.password_reset');
    // Carry the browser cookie across synthetic requests to exercise real flashed errors.
    $this->withCookie(config()->string('session.cookie'), session()->getId())->get('/account/reset-password')
        ->assertDontSee('PRIVATE_RESET_SENTINEL')->assertDontSee(str_repeat('b', 64))
        ->assertSee('new password field confirmation')->assertDontSee('password reset.password');
});

it('IdentityCustomer recovery HTTP bounds requests by canonical identity and IP', function (): void {
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->post('/account/forgot-password', ['email' => $attempt % 2 ? 'SHOPPER@example.test' : 'shopper@example.test'])->assertRedirect('/account/forgot-password');
    }
    $this->post('/account/forgot-password', ['email' => 'SHOPPER@example.test'])->assertTooManyRequests();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/account/reset-password', ['challenge_token' => 'invalid'])->assertUnprocessable();
    }
    $this->postJson('/account/reset-password', ['challenge_token' => 'invalid'])->assertTooManyRequests();
});

it('IdentityCustomer recovery HTTP restricts resend to the current account and bounds repeated sends', function (): void {
    $this->post('/account/verification')->assertRedirect('/account/login');
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->post('/account/verification', ['email' => 'other@example.test'])->assertRedirect('/account/verify');
    }
    expect(DB::table('customer_challenges')->where('customer_id', $customer->id)->count())->toBe(3);
    expect(DB::table('customer_challenges')->whereNull('revoked_at')->count())->toBe(1);
    $this->post('/account/verification')->assertTooManyRequests();
});

it('IdentityCustomer recovery HTTP remains unavailable before installation', function (): void {
    DB::table('installations')->where('id', 1)->update(['status' => 'pending_owner']);
    foreach (['/account/verify', '/account/forgot-password', '/account/reset-password'] as $path) {
        $this->get($path)->assertNotFound();
        $this->post($path)->assertNotFound();
    }
    $this->post('/account/verification')->assertNotFound();
    expect(CustomerAccount::query()->count())->toBe(0);
});

it('IdentityCustomer recovery HTTP bounds mail requests for one address across different IPs', function (): void {
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($attempt + 1)])
            ->post('/account/forgot-password', ['email' => 'unknown@example.test'])->assertRedirect('/account/forgot-password');
    }
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])
        ->post('/account/forgot-password', ['email' => 'UNKNOWN@example.test'])->assertTooManyRequests();
});
