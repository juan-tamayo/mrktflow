<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Actions\AcceptStaffInvitation;
use App\Domain\Identity\Actions\InviteStaff;
use App\Domain\Identity\Models\StaffAccount;
use App\Http\Support\StaffSession;
use App\Http\Support\StaffTwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class StaffInvitationController
{
    public function create(Request $request, InviteStaff $invite, StaffTwoFactor $twoFactor): JsonResponse
    {
        Gate::authorize('staff.manage');
        if ($twoFactor->requiresReauthentication($request)) {
            $request->session()->put('staff_reauth_required', true);
            abort(403);
        }
        $actor = $request->user('staff');
        abort_unless($actor instanceof StaffAccount, 403);
        $input = $request->validate(['invitation' => ['required', 'array']]);
        $issued = $invite->invite($actor, $input['invitation']);

        // A one-time JSON response keeps the secret out of Inertia props/session flash/history.
        return response()->json(['path' => '/staff/invitation#'.$issued->token, 'expires_at' => $issued->expiresAt->toIso8601String()], 201);
    }

    public function show(): Response
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 404);

        return Inertia::render('AcceptStaffInvitation');
    }

    public function accept(Request $request, AcceptStaffInvitation $accept, StaffSession $session): RedirectResponse
    {
        $input = $request->validate(['invitation_token' => ['required', 'string', 'size:64'], 'owner' => ['required', 'array']]);
        try {
            $staff = $accept->accept($input['invitation_token'], $input['owner']);
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [404, 409], true) && ! $request->expectsJson()) {
                throw ValidationException::withMessages(['invitation_token' => 'This invitation is invalid, expired or already used. Ask an owner for a new invitation.'])->redirectTo('/staff/invitation');
            }
            throw $exception;
        }
        $session->establish($request, $staff);

        return redirect(app(StaffTwoFactor::class)->destination($staff));
    }
}
