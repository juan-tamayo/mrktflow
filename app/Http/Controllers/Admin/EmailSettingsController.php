<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Operations\Actions\ReadEmailSettings;
use App\Domain\Operations\Actions\SendTestNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class EmailSettingsController
{
    public function show(Request $request, ReadEmailSettings $settings): Response
    {
        Gate::authorize('settings.manage');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $template = $request->query('template');

        return Inertia::render('EmailSettings', $settings->page(is_string($template) ? $template : null) + [
            'staff' => ['name' => $staff->name, 'role' => $staff->role],
            'tested' => session()->get('email_tested', false),
        ]);
    }

    public function test(Request $request, SendTestNotification $send): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $request->validate([
            'template' => ['required', 'string', 'max:32'],
            'idempotency_key' => ['required', 'uuid'],
            'recipient' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:254'],
        ]);
        try {
            $send->send($staff, $input);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/email');
        }

        return redirect('/admin/email')->with('email_tested', true);
    }
}
