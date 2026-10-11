<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Application\Identity\RequestCustomerChallengeMail;
use App\Domain\Identity\Actions\ResetCustomerPassword;
use App\Http\Support\CustomerSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Timebox;

final class CustomerPasswordRecoveryController
{
    public function requestForm(): Response
    {
        return response()->view('system.customer-forgot-password')->header('Referrer-Policy', 'no-referrer');
    }

    public function request(Request $request, RequestCustomerChallengeMail $mail, Timebox $timebox): RedirectResponse
    {
        $input = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']]);
        $timebox->call(fn () => $mail->passwordReset($input['email']), 200000);

        return redirect('/account/forgot-password')->with('status', 'If an eligible customer account matches that email, a reset message has been queued. Check your inbox for the latest code.');
    }

    public function resetForm(): Response
    {
        return response()->view('system.customer-reset-password')->header('Referrer-Policy', 'no-referrer');
    }

    public function reset(Request $request, ResetCustomerPassword $reset, CustomerSession $session): RedirectResponse
    {
        $input = $request->validate([
            'challenge_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'password_reset' => ['required', 'array:password,password_confirmation'],
        ]);
        $reset->reset($input['challenge_token'], $input['password_reset']);
        $session->destroy($request);

        return redirect('/account/login')->with('status', 'Your password has been changed. Sign in with your new password.');
    }
}
