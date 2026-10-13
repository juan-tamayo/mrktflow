<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Identity\Actions\ManageTwoFactor;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Identity\Support\StaffTotp;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class StaffTwoFactor
{
    public function destination(StaffAccount $staff): string
    {
        return StaffPermissions::requiresTwoFactor($staff) ? '/admin/two-factor' : '/admin';
    }

    public function pending(Request $request, StaffAccount $staff): ?string
    {
        if (! StaffPermissions::requiresTwoFactor($staff)) {
            return null;
        }
        if (! app(ManageTwoFactor::class)->confirmed($staff)) {
            return 'enroll';
        }

        return $request->session()->get('staff_two_factor_verified') === true ? null : 'challenge';
    }

    public function complete(Request $request, StaffAccount $staff): void
    {
        $request->session()->regenerate(true);
        $request->session()->put('staff_session_version', $staff->session_version);
        $request->session()->put('staff_two_factor_verified', true);
        $this->markReauthenticated($request);
        Inertia::clearHistory();
    }

    public function markReauthenticated(Request $request): void
    {
        $request->session()->put('staff_reauthenticated_at', now()->getTimestamp());
    }

    public function isReauthenticated(Request $request): bool
    {
        $at = $request->session()->get('staff_reauthenticated_at');

        return is_int($at) && $at >= now()->subMinutes(StaffTotp::REAUTH_MINUTES)->getTimestamp();
    }

    public function requiresReauthentication(Request $request): bool
    {
        $staff = $request->user('staff');

        return $staff instanceof StaffAccount && StaffPermissions::requiresTwoFactor($staff) && ! $this->isReauthenticated($request);
    }
}
