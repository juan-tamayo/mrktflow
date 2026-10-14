<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Http\Middleware\RequireCustomerSession;
use App\Http\Support\CustomerSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\CustomerRegistration;

beforeEach(function (): void {
    DB::table('installations')->where('id', 1)->update(['status' => 'complete', 'completed_at' => now()]);
    Route::middleware(['web', RequireCustomerSession::class])->get('/__customer-test/account', fn () => response()->json(['id' => Auth::guard('customer')->id()]));
});

function customerSessionRequest(): Request
{
    $request = Request::create('/account', 'GET');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->start();

    return $request;
}

it('IdentityCustomer rotates the session and CSRF token while retaining current guest intent', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $request = customerSessionRequest();
    $request->session()->put(['guest_identity' => 'synthetic-current-guest', 'setup_access' => ['secret' => 'synthetic'], '_old_input' => ['password' => 'synthetic']]);
    $oldId = $request->session()->getId();
    $oldToken = $request->session()->token();
    app(CustomerSession::class)->establish($request, $customer);
    expect($request->session()->getId())->not->toBe($oldId);
    expect($request->session()->token())->not->toBe($oldToken);
    expect($request->session()->get('guest_identity'))->toBe('synthetic-current-guest');
    expect($request->session()->has('setup_access'))->toBeFalse();
    expect($request->session()->has('_old_input'))->toBeFalse();
    expect($request->session()->get('customer_session_version'))->toBe(1);
    expect(Auth::guard('customer')->id())->toBe($customer->id);
    expect(Auth::guard('staff')->check())->toBeFalse();
});

it('IdentityCustomer invalidates private browser state on logout', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $request = customerSessionRequest();
    app(CustomerSession::class)->establish($request, $customer);
    $request->session()->put('private_account_data', 'synthetic');
    $oldId = $request->session()->getId();
    $oldToken = $request->session()->token();
    app(CustomerSession::class)->destroy($request);
    expect(Auth::guard('customer')->check())->toBeFalse();
    expect($request->session()->has('private_account_data'))->toBeFalse();
    expect($request->session()->has('customer_session_version'))->toBeFalse();
    expect($request->session()->getId())->not->toBe($oldId);
    expect($request->session()->token())->not->toBe($oldToken);
});

it('IdentityCustomer refuses to establish a disabled or revoked stale session', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $request = customerSessionRequest();
    DB::table('customer_accounts')->where('id', $customer->id)->update(['session_version' => 2]);
    expect(fn () => app(CustomerSession::class)->establish($request, $customer))->toThrow(AuthorizationException::class);
    DB::table('customer_accounts')->where('id', $customer->id)->update(['session_version' => 1, 'is_active' => false]);
    expect(fn () => app(CustomerSession::class)->establish($request, $customer))->toThrow(AuthorizationException::class);
    expect(Auth::guard('customer')->check())->toBeFalse();
});

it('IdentityCustomer requires a live customer session and excludes staff-only authentication', function (): void {
    $this->getJson('/__customer-test/account')->assertUnauthorized();
    $staff = new StaffAccount;
    $staff->forceFill(['name' => 'Fictional Owner', 'email' => 'staff@example.test', 'password' => Hash::make('Staff-only passphrase 471!'), 'role' => 'owner', 'permissions' => StaffPermissions::forRole('owner'), 'is_active' => true])->save();
    $this->actingAs($staff, 'staff')->withSession(['staff_session_version' => 1])->getJson('/__customer-test/account')->assertUnauthorized();
    expect(Auth::guard('staff')->id())->toBe($staff->id);
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])->getJson('/__customer-test/account')->assertOk()->assertJson(['id' => $customer->id]);
});

it('IdentityCustomer rejects foreign principals even when their UUID matches a real customer', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    $foreign = new StaffAccount;
    $foreign->forceFill(['id' => $customer->id, 'is_active' => true, 'session_version' => 1]);
    $this->actingAs($foreign, 'customer')->withSession(['customer_session_version' => 1])->getJson('/__customer-test/account')->assertUnauthorized()->assertSessionMissing('customer_session_version');
});

it('IdentityCustomer rechecks activity and versions before every private request', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    foreach ([['session_version' => 2], ['session_version' => 1, 'is_active' => false]] as $change) {
        DB::table('customer_accounts')->where('id', $customer->id)->update($change);
        $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])->getJson('/__customer-test/account')->assertUnauthorized()->assertSessionMissing('customer_session_version');
    }
});

it('IdentityCustomer rejects incomplete installations before private account access', function (): void {
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    DB::table('installations')->where('id', 1)->update(['status' => 'pending_owner']);
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])->getJson('/__customer-test/account')->assertServiceUnavailable();
});
