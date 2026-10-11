<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Application\Identity\RequestCustomerChallengeMail;
use App\Domain\Identity\Actions\VerifyCustomerEmail;
use App\Domain\Identity\Models\CustomerAccount;
use App\Http\Support\CustomerSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CustomerEmailVerificationController
{
    public function show(Request $request): Response
    {
        return response()->view('system.customer-verify', ['signedIn' => $request->user('customer') instanceof CustomerAccount])->header('Referrer-Policy', 'no-referrer');
    }

    public function send(Request $request, RequestCustomerChallengeMail $mail): RedirectResponse
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof CustomerAccount, 403);
        $mail->verification($customer);

        return redirect('/account/verify')->with('status', 'If your email needs confirmation, a message has been queued. Check your inbox for the latest code.');
    }

    public function verify(Request $request, VerifyCustomerEmail $verify, CustomerSession $session): RedirectResponse
    {
        $input = $request->validate(['challenge_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
        $customer = $request->user('customer');
        abort_unless($customer instanceof CustomerAccount, 403);
        $verified = $verify->verify($customer, $input['challenge_token']);
        $session->establish($request, $verified);

        return redirect('/account')->with('status', 'Your email address is confirmed.');
    }
}
