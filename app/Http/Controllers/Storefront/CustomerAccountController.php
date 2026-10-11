<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Application\Identity\RequestCustomerChallengeMail;
use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Models\CustomerAccount;
use App\Http\Support\CustomerSession;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerAccountController
{
    public function registerForm(): View
    {
        return view('system.customer-register');
    }

    public function loginForm(): View
    {
        return view('system.customer-login');
    }

    public function register(Request $request, RegisterCustomer $register, CustomerSession $session): RedirectResponse
    {
        $input = $request->validate(['customer' => ['required', 'array:name,email,password,password_confirmation']]);
        $customer = DB::transaction(function () use ($register, $input): CustomerAccount {
            $customer = $register->register($input['customer']);
            app(RequestCustomerChallengeMail::class)->verification($customer);

            return $customer;
        });
        $session->establish($request, $customer);

        return redirect('/account');
    }

    public function login(Request $request, CustomerSession $session): RedirectResponse
    {
        $input = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('Use a password of at most 72 bytes.');
                }
            }],
        ]);
        if (! Auth::guard('customer')->attempt(['email' => mb_strtolower(trim($input['email']), 'UTF-8'), 'password' => $input['password'], 'is_active' => true], false)) {
            throw ValidationException::withMessages(['email' => 'The sign-in details could not be verified.']);
        }
        $customer = Auth::guard('customer')->user();
        abort_unless($customer instanceof CustomerAccount, 403);
        $session->establish($request, $customer);

        return redirect('/account');
    }

    public function home(Request $request): View
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof CustomerAccount, 403);

        return view('system.customer-home', ['customer' => ['name' => $customer->name, 'email' => $customer->email, 'verified' => $customer->email_verified_at !== null]]);
    }

    public function logout(Request $request, CustomerSession $session): RedirectResponse
    {
        $session->destroy($request);

        return redirect('/account/login');
    }
}
