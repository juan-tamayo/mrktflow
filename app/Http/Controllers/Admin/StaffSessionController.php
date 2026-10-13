<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Http\Support\StaffSession;
use App\Http\Support\StaffTwoFactor;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class StaffSessionController
{
    public function show(): Response
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 404);

        return Inertia::render('StaffLogin');
    }

    public function login(Request $request, StaffSession $session): RedirectResponse
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 404);
        $input = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('Use a password of at most 72 bytes.');
                }
            }],
        ]);
        $credentials = ['email' => mb_strtolower(trim($input['email']), 'UTF-8'), 'password' => $input['password'], 'is_active' => true];
        if (! Auth::guard('staff')->attempt($credentials, false)) {
            throw ValidationException::withMessages(['email' => 'The sign-in details could not be verified.']);
        }
        $staff = Auth::guard('staff')->user();
        abort_unless($staff instanceof StaffAccount, 403);
        $session->rotate($request, $staff);

        return redirect(app(StaffTwoFactor::class)->destination($staff));
    }

    public function logout(Request $request, StaffSession $session): RedirectResponse
    {
        $session->destroy($request);

        return redirect('/admin/login');
    }

    public function home(Request $request): Response
    {
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return Inertia::render('AdminHome', ['staff' => ['name' => $staff->name, 'role' => $staff->role], 'canManageStaff' => $staff->hasPermission('staff.manage'), 'canPreviewTheme' => $staff->hasPermission('content.read')]);
    }
}
