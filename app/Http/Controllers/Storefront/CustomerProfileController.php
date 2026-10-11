<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Application\Identity\RequestCustomerChallengeMail;
use App\Domain\Identity\Actions\UpdateCustomerProfile;
use App\Domain\Identity\Models\CustomerAccount;
use App\Http\Support\CustomerSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CustomerProfileController
{
    public function show(Request $request): View
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof CustomerAccount, 403);

        return view('system.customer-profile', ['profile' => ['name' => $customer->name, 'email' => $customer->email, 'version' => $customer->profile_version]]);
    }

    public function update(Request $request, UpdateCustomerProfile $update, CustomerSession $session): RedirectResponse
    {
        $input = $request->validate(['profile' => ['required', 'array:name,email,version,current_password']]);
        $actor = $request->user('customer');
        abort_unless($actor instanceof CustomerAccount, 403);
        $customer = DB::transaction(function () use ($actor, $input, $update): CustomerAccount {
            $customer = $update->update($actor, $input['profile']);
            if ($customer->email !== $actor->email) {
                app(RequestCustomerChallengeMail::class)->verification($customer);
            }

            return $customer;
        });
        $session->establish($request, $customer);

        return redirect('/account/profile')->with('status', $actor->email === $customer->email ? 'Your profile is saved.' : 'Your profile is saved. Confirm your new email using the message queued for that address.');
    }
}
