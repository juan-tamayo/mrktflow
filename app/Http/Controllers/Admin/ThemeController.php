<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\ThemesContent\Actions\ActivateTheme;
use App\Domain\ThemesContent\Contracts\ThemeRegistry;
use App\Domain\ThemesContent\Exceptions\ThemeActivationFailed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff theme activation (THEME-06, T068).
 *
 * Listing sits under `content.read`; activation sits under
 * `content.publish`. The page only reflects those gates.
 */
final class ThemeController
{
    public function show(Request $request, ThemeRegistry $registry): Response
    {
        Gate::authorize('content.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $themes = [];
        foreach ($registry->list() as $theme) {
            $themes[] = [
                'theme_id' => $theme->themeId,
                'name' => $theme->name,
                'version' => $theme->version,
                'status' => $theme->status,
                'activatable' => $theme->activatable(),
                'errors' => $theme->errors,
            ];
        }

        $active = DB::table('theme_activations')->whereNull('replaced_at')->value('theme_id');

        return Inertia::render('Themes', [
            'themes' => $themes,
            'activeThemeId' => is_string($active) ? $active : null,
            'canActivate' => $staff->hasPermission('content.publish'),
            'activated' => session()->get('theme_activated', false),
        ]);
    }

    public function activate(Request $request, ActivateTheme $activate): RedirectResponse
    {
        Gate::authorize('content.publish');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $request->validate([
            'theme_id' => ['required', 'string', 'max:191'],
            'section_types' => ['sometimes', 'array'],
            'template_keys' => ['sometimes', 'array'],
        ]);
        try {
            $activate->activate($staff, $input);
        } catch (ThemeActivationFailed $failure) {
            throw ValidationException::withMessages(['theme_id' => $failure->errors()])->redirectTo('/admin/themes');
        }

        return redirect('/admin/themes')->with('theme_activated', true);
    }
}
