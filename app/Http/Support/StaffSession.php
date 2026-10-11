<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class StaffSession
{
    public function establish(Request $request, StaffAccount $staff): void
    {
        Auth::guard('staff')->login($staff, false);
        $this->rotate($request, $staff);
    }

    public function rotate(Request $request, StaffAccount $staff): void
    {
        $request->session()->regenerate(true);
        $request->session()->forget(['setup_access', 'setup_saved', '_old_input']);
        $request->session()->put('staff_session_version', $staff->session_version);
    }

    public function destroy(Request $request): void
    {
        Auth::guard('staff')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
