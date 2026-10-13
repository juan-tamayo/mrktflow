<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\StaffAccount;
use App\Http\Support\StaffSession;
use App\Http\Support\StaffTwoFactor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class RequireStaffSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (DB::table('installations')->where('id', 1)->value('status') !== 'complete') {
            return Inertia::render('AdminUnavailable')->toResponse($request)->setStatusCode(503);
        }
        $principal = Auth::guard('staff')->user();
        $staff = $principal instanceof StaffAccount ? StaffAccount::query()->find($principal->id) : null;
        if ($staff === null || ! $staff->is_active || $request->session()->get('staff_session_version') !== $staff->session_version) {
            if ($principal !== null || $request->session()->has('staff_session_version')) {
                app(StaffSession::class)->destroy($request);
            }
            abort_if($request->expectsJson(), 401);

            return redirect('/admin/login');
        }
        Auth::guard('staff')->setUser($staff);
        Gate::forUser($staff)->authorize('admin.access');
        $pending = app(StaffTwoFactor::class)->pending($request, $staff);
        if ($pending !== null && ! $request->is('admin/two-factor', 'admin/two-factor/*') && ! $request->is('admin/logout')) {
            abort_if($request->expectsJson(), 403);
            if ($request->isMethod('GET')) {
                return redirect('/admin/two-factor');
            }

            return redirect('/admin/two-factor');
        }

        return $next($request);
    }
}
