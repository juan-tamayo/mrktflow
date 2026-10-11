<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\CustomerAccount;
use App\Http\Support\CustomerSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class RequireCustomerGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        $principal = Auth::guard('customer')->user();
        $customer = $principal instanceof CustomerAccount ? CustomerAccount::query()->find($principal->id) : null;
        if ($customer !== null && $customer->is_active && $request->session()->get('customer_session_version') === $customer->session_version) {
            return redirect('/account');
        }
        if ($principal !== null || $request->session()->has('customer_session_version')) {
            app(CustomerSession::class)->destroy($request);
        }

        return $next($request);
    }
}
