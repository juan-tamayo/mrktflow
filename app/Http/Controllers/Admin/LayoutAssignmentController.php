<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\ThemesContent\Actions\AssignLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Staff visual template assignment (THEME-02, T066). Writing requires
 * `content.write`; the picker only reflects that gate.
 */
final class LayoutAssignmentController
{
    public function update(Request $request, AssignLayout $assign): RedirectResponse
    {
        Gate::authorize('content.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $request->validate([
            'subject_type' => ['required', 'string'],
            'subject_id' => ['required', 'uuid'],
            'template_key' => ['required', 'string'],
        ]);
        try {
            $assign->assign($staff, $input);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo((string) url()->previous('/admin'));
        }

        return redirect()->to((string) url()->previous('/admin'))->with('layout_saved', true);
    }
}
