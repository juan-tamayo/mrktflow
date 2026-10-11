<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Identity\Models\CustomerAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

final class CustomerSession
{
    public function establish(Request $request, CustomerAccount $actor): void
    {
        $customer = CustomerAccount::query()->find($actor->id);
        if ($customer === null || ! $customer->is_active || $customer->session_version !== $actor->session_version
            || $customer->email !== $actor->email) {
            throw new AuthorizationException('Customer access is unavailable.');
        }
        Auth::guard('customer')->login($customer, false);
        $request->session()->regenerate(true);
        $request->session()->forget(['setup_access', 'setup_saved', '_old_input']);
        $request->session()->put('customer_session_version', $customer->session_version);
        Inertia::clearHistory();
    }

    public function destroy(Request $request): void
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();
    }
}
