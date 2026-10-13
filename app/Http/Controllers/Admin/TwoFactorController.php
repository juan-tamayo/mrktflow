<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Actions\ManageTwoFactor;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Http\Support\StaffTwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class TwoFactorController
{
    public function show(Request $request, ManageTwoFactor $twoFactor, StaffTwoFactor $gate): Response|RedirectResponse
    {
        $staff = $this->staff($request);
        $pending = $gate->pending($request, $staff);
        $codes = $request->session()->pull('staff_recovery_codes');
        if (is_array($codes) && $codes !== []) {
            $safe = [];
            foreach ($codes as $code) {
                if (is_string($code)) {
                    $safe[] = $code;
                }
            }

            return Inertia::render('TwoFactor', [
                'mode' => 'codes', 'recoveryCodes' => $safe, 'issuer' => null, 'label' => null, 'secret' => null, 'uri' => null,
            ]);
        }
        if ($pending === 'enroll' || ($pending === null && StaffPermissions::requiresTwoFactor($staff) && ! $twoFactor->confirmed($staff))) {
            $enrollment = $twoFactor->start($staff);

            return Inertia::render('TwoFactor', [
                'mode' => 'enroll', 'secret' => $enrollment->secret, 'uri' => $enrollment->uri,
                'issuer' => $enrollment->issuer, 'label' => $enrollment->label, 'recoveryCodes' => [],
            ]);
        }
        if ($pending === 'challenge') {
            return Inertia::render('TwoFactor', [
                'mode' => 'challenge', 'secret' => null, 'uri' => null, 'issuer' => null, 'label' => null, 'recoveryCodes' => [],
            ]);
        }
        if ($pending !== null) {
            return redirect('/admin/two-factor');
        }

        return Inertia::render('TwoFactor', [
            'mode' => 'reauthenticate', 'secret' => null, 'uri' => null, 'issuer' => null, 'label' => null, 'recoveryCodes' => [],
        ]);
    }

    public function confirm(Request $request, ManageTwoFactor $twoFactor, StaffTwoFactor $gate): RedirectResponse
    {
        $staff = $this->staff($request);
        abort_unless($gate->pending($request, $staff) === 'enroll', 403);
        $input = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $enrollment = $twoFactor->confirm($staff, $input['code']);
        $gate->complete($request, $staff);
        $request->session()->put('staff_recovery_codes', $enrollment->recoveryCodes);

        return redirect('/admin/two-factor');
    }

    public function challenge(Request $request, ManageTwoFactor $twoFactor, StaffTwoFactor $gate): RedirectResponse
    {
        $staff = $this->staff($request);
        abort_unless($gate->pending($request, $staff) === 'challenge', 403);
        $input = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $method = $twoFactor->challenge($staff, $input['code']);
        if ($method === 'recovery') {
            $request->session()->forget('staff_two_factor_verified');

            return redirect('/admin/two-factor');
        }
        $gate->complete($request, $staff);

        return redirect('/admin');
    }

    public function reauthenticate(Request $request, ManageTwoFactor $twoFactor, StaffTwoFactor $gate): RedirectResponse
    {
        $staff = $this->staff($request);
        abort_unless($gate->pending($request, $staff) === null, 403);
        abort_unless(StaffPermissions::requiresTwoFactor($staff), 403);
        $input = $request->validate([
            'password' => ['required', 'string', 'max:72'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        if (! Hash::check($input['password'], $staff->password)) {
            throw ValidationException::withMessages(['password' => 'The sign-in details could not be verified.']);
        }
        $twoFactor->challenge($staff, $input['code']);
        $gate->markReauthenticated($request);
        $request->session()->forget('staff_reauth_required');
        $intended = $request->session()->pull('staff_reauth_intended');

        return redirect(is_string($intended) && str_starts_with($intended, '/') ? $intended : '/admin');
    }

    private function staff(Request $request): StaffAccount
    {
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return $staff;
    }
}
