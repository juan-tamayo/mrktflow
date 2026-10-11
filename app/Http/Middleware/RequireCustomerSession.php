<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\CustomerAccount;
use App\Http\Support\CustomerSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class RequireCustomerSession
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 503);
        $principal = Auth::guard('customer')->user();
        $customer = $principal instanceof CustomerAccount ? CustomerAccount::query()->find($principal->id) : null;
        if ($customer === null || ! $customer->is_active || $request->session()->get('customer_session_version') !== $customer->session_version) {
            if ($principal !== null || $request->session()->has('customer_session_version')) {
                app(CustomerSession::class)->destroy($request);
            }
            abort_if($request->expectsJson(), 401);

            return redirect('/account/login');
        }
        Auth::guard('customer')->setUser($customer);

        return $next($request);
    }
}
