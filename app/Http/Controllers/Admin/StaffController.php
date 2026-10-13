<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Actions\ChangeStaffAccess;
use App\Domain\Identity\Models\StaffAccount;
use App\Http\Support\StaffTwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class StaffController
{
    public function index(): Response
    {
        Gate::authorize('staff.manage');
        $accounts = StaffAccount::query()->orderBy('name')->orderBy('id')->paginate(50)->through(fn (StaffAccount $staff): array => [
            'id' => $staff->id, 'name' => $staff->name, 'email' => $staff->email, 'role' => $staff->role,
            'is_active' => $staff->is_active, 'version' => $staff->session_version,
        ]);

        return Inertia::render('Staff', ['accounts' => [
            'data' => $accounts->items(), 'next_page_url' => $accounts->nextPageUrl(), 'prev_page_url' => $accounts->previousPageUrl(),
        ], 'saved' => session()->get('staff_saved', false)]);
    }

    public function update(Request $request, string $staffId, ChangeStaffAccess $change, StaffTwoFactor $twoFactor): RedirectResponse
    {
        Gate::authorize('staff.manage');
        if ($twoFactor->requiresReauthentication($request)) {
            $request->session()->put('staff_reauth_required', true);
            $request->session()->put('staff_reauth_intended', '/admin/staff');
            abort_if($request->expectsJson(), 403);

            return redirect('/admin/two-factor');
        }
        $actor = $request->user('staff');
        abort_unless($actor instanceof StaffAccount, 403);
        $input = $request->validate(['access' => ['required', 'array']]);
        try {
            $change->change($actor, $staffId, $input['access']);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 409 && ! $request->expectsJson()) {
                throw ValidationException::withMessages(['access' => $exception->getMessage()])->redirectTo('/admin/staff');
            }
            throw $exception;
        }

        return redirect('/admin/staff')->with('staff_saved', true);
    }
}
