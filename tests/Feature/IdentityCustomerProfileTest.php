<?php

declare(strict_types=1);

use App\Application\Identity\RequestCustomerChallengeMail;
use App\Domain\Identity\Actions\CustomerAddresses;
use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Actions\UpdateCustomerProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\CustomerRegistration;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    DB::table('installations')->where('id', 1)->update(['status' => 'complete', 'completed_at' => now()]);
});

function customerSavedAddress(): array
{
    return ['label' => 'Home', 'recipient' => 'Fictional Recipient', 'line1' => '123 Fictional Street', 'line2' => null, 'city' => 'Example City', 'region' => 'Example Region', 'postal_code' => 'A1A 1A1', 'country_code' => 'CA'];
}

it('IdentityCustomer profile updates a name without changing verified identity and audits only IDs', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $customer->forceFill(['email_verified_at' => now()])->save();
    $updated = app(UpdateCustomerProfile::class)->update($customer, ['name' => 'Updated Customer', 'email' => strtoupper($customer->email), 'version' => 1]);
    expect($updated->name)->toBe('Updated Customer');
    expect($updated->email)->toBe($customer->email);
    expect($updated->email_verified_at)->not->toBeNull();
    expect($updated->session_version)->toBe(1);
    expect($updated->profile_version)->toBe(2);
    expect(DB::table('outbox_events')->where('intent_key', 'customer:profile:'.$customer->id.':2')->exists())->toBeTrue();
});

it('IdentityCustomer profile requires the current password to change email and revokes prior identity', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $customer->forceFill(['email_verified_at' => now()])->save();
    app(RequestCustomerChallengeMail::class)->passwordReset($customer->email);
    $input = ['name' => $customer->name, 'email' => 'changed@example.test', 'version' => 1];
    expect(fn () => app(UpdateCustomerProfile::class)->update($customer, $input))->toThrow(ValidationException::class);
    expect(fn () => app(UpdateCustomerProfile::class)->update($customer, $input + ['current_password' => 'Wrong fictional passphrase']))->toThrow(ValidationException::class);
    expect($customer->fresh()->email)->toBe($customer->email);
    $updated = app(UpdateCustomerProfile::class)->update($customer, $input + ['current_password' => CustomerRegistration::input()['password']]);
    expect($updated->email)->toBe('changed@example.test');
    expect($updated->email_verified_at)->toBeNull();
    expect($updated->session_version)->toBe(2);
    expect(DB::table('customer_challenges')->whereNotNull('token_digest')->count())->toBe(0);
});

it('IdentityCustomer profile rejects stale versions duplicate email and privilege fields', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), ['email' => 'taken@example.test']));
    $input = ['name' => $customer->name, 'email' => $customer->email, 'version' => 1];
    expect(fn () => app(UpdateCustomerProfile::class)->update($customer, $input + ['is_active' => true]))->toThrow(ValidationException::class);
    expect(fn () => app(UpdateCustomerProfile::class)->update($customer, array_replace($input, ['email' => 'taken@example.test', 'current_password' => CustomerRegistration::input()['password']])))->toThrow(ValidationException::class);
    expect(fn () => app(UpdateCustomerProfile::class)->update($customer, array_replace($input, ['version' => 2])))->toThrow(HttpException::class);
});

it('IdentityCustomer addresses encrypt private fields enforce ownership and version all edits', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $other = app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), ['email' => 'other@example.test']));
    $addresses = app(CustomerAddresses::class);
    $id = $addresses->save($customer, customerSavedAddress());
    expect(DB::table('customer_addresses')->where('id', $id)->value('payload'))->not->toContain('Fictional Street')->not->toContain('Fictional Recipient');
    expect($addresses->all($customer))->toHaveCount(1);
    expect($addresses->all($other))->toBe([]);
    expect(fn () => $addresses->save($other, customerSavedAddress() + ['version' => 1], $id))->toThrow(HttpException::class);
    expect(fn () => $addresses->remove($other, $id, 1))->toThrow(HttpException::class);
    $addresses->save($customer, array_replace(customerSavedAddress(), ['city' => 'Changed City', 'version' => 1]), $id);
    expect($addresses->all($customer)[0]['city'])->toBe('Changed City');
    expect(fn () => $addresses->save($customer, customerSavedAddress() + ['version' => 1], $id))->toThrow(HttpException::class);
    expect(fn () => $addresses->remove($customer, $id, 1))->toThrow(HttpException::class);
    $addresses->remove($customer, $id, 2);
    expect($addresses->all($customer))->toBe([]);
});

it('IdentityCustomer addresses bound saved data and reject unsupported countries or ownership injection', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $addresses = app(CustomerAddresses::class);
    expect(fn () => $addresses->save($customer, customerSavedAddress() + ['customer_id' => $customer->id]))->toThrow(ValidationException::class);
    expect(fn () => $addresses->save($customer, array_replace(customerSavedAddress(), ['country_code' => 'ZZ'])))->toThrow(ValidationException::class);
    for ($index = 0; $index < 20; $index++) {
        $addresses->save($customer, customerSavedAddress());
    }
    expect(fn () => $addresses->save($customer, customerSavedAddress()))->toThrow(ValidationException::class);
    expect($addresses->all($customer))->toHaveCount(20);
    $customer->is_active = false;
    $customer->save();
    expect(fn () => $addresses->all($customer))->toThrow(AuthorizationException::class);
});

it('IdentityCustomer profile and address changes roll back if audit intent fails', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    config(['outbox.consumers' => ['platform.audit.recorded' => []]]);
    expect(fn () => app(UpdateCustomerProfile::class)->update($customer, ['name' => 'Changed Name', 'email' => $customer->email, 'version' => 1]))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(CustomerAddresses::class)->save($customer, customerSavedAddress()))->toThrow(InvalidArgumentException::class);
    expect($customer->fresh()->name)->toBe(CustomerRegistration::input()['name']);
    expect(DB::table('customer_addresses')->count())->toBe(0);
});

it('IdentityCustomer profile HTTP changes email atomically queues new confirmation and retires other sessions', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/profile')->assertOk()->assertDontSee($customer->password);
    $this->post('/account/profile', ['profile' => ['name' => 'New Customer', 'email' => 'NEW@example.test', 'version' => 1, 'current_password' => CustomerRegistration::input()['password']]])->assertRedirect('/account/profile');
    expect(session('customer_session_version'))->toBe(2);
    expect(DB::table('customer_challenge_deliveries')->count())->toBe(1);
    expect($customer->fresh()->email)->toBe('new@example.test');
    $this->getJson('/admin')->assertUnauthorized();
});

it('IdentityCustomer address HTTP hides foreign objects and never flashes private submission fields', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $other = app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), ['email' => 'other@example.test']));
    $id = app(CustomerAddresses::class)->save($customer, customerSavedAddress());
    $this->actingAs($other, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/addresses/'.$id)->assertNotFound();
    $this->post('/account/addresses/'.$id, ['address' => customerSavedAddress() + ['version' => 1]])->assertNotFound();
    $this->post('/account/addresses/'.$id.'/delete', ['version' => 1])->assertNotFound();
    $this->from('/account/addresses')->post('/account/addresses', ['address' => array_replace(customerSavedAddress(), ['recipient' => 'PRIVATE_ADDRESS_SENTINEL', 'country_code' => 'ZZ'])])
        ->assertRedirect('/account/addresses')->assertSessionHasErrors('address.country_code')->assertSessionMissing('_old_input.address');
});

it('IdentityCustomer profile HTTP rejects secret and privilege injection without flashing input', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->from('/account/profile')->post('/account/profile', ['profile' => ['name' => 'New Name', 'email' => 'changed@example.test', 'version' => 1, 'current_password' => 'PRIVATE_PROFILE_SENTINEL']])
        ->assertRedirect('/account/profile')->assertSessionHasErrors('profile.current_password')->assertSessionMissing('_old_input.profile');
    $this->postJson('/account/profile', ['profile' => ['name' => 'New Name', 'email' => $customer->email, 'version' => 1, 'role' => 'owner']])->assertUnprocessable();
    expect($customer->fresh()->name)->toBe($customer->name);
    expect($customer->fresh()->email)->toBe($customer->email);
    $this->get('/account/profile')->assertDontSee('PRIVATE_PROFILE_SENTINEL');
});

it('IdentityCustomer address HTTP escapes saved markup and rejects stale or missing objects', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $id = app(CustomerAddresses::class)->save($customer, array_replace(customerSavedAddress(), ['label' => '<img src=x onerror=alert(1)>']));
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->get('/account/addresses')->assertOk()->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)->assertDontSee('<img src=x', false);
    $this->post('/account/addresses/'.$id, ['address' => customerSavedAddress() + ['version' => 2]])->assertConflict();
    $this->post('/account/addresses/'.$id.'/delete', ['version' => 2])->assertConflict();
    $this->get('/account/addresses/00000000-0000-4000-8000-000000000000')->assertNotFound();
    $this->post('/account/addresses/'.$id.'/delete', ['version' => 1])->assertRedirect('/account/addresses');
    expect(DB::table('customer_addresses')->count())->toBe(0);
});

it('IdentityCustomer addresses reject ciphertext swapped between customer records', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $other = app(RegisterCustomer::class)->register(array_replace(CustomerRegistration::input(), ['email' => 'other@example.test']));
    $addresses = app(CustomerAddresses::class);
    $first = $addresses->save($customer, customerSavedAddress());
    $second = $addresses->save($other, customerSavedAddress());
    DB::table('customer_addresses')->where('id', $second)->update(['payload' => DB::table('customer_addresses')->where('id', $first)->value('payload')]);
    expect(fn () => $addresses->all($other))->toThrow(RuntimeException::class, 'Invalid saved address binding.');
});
