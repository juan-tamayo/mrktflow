<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Operations\Actions\ListNotificationFailures;
use App\Domain\Operations\Actions\RetryNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationFailureController
{
    public function index(Request $request, ListNotificationFailures $failures): Response
    {
        Gate::authorize('settings.manage');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return Inertia::render('NotificationFailures', $failures->page() + [
            'staff' => ['name' => $staff->name, 'role' => $staff->role],
            'retried' => session()->get('notification_retried', false),
        ]);
    }

    public function retry(Request $request, string $deliveryId, RetryNotification $retry): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        try {
            $retry->retry($staff, $deliveryId);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/notifications');
        }

        return redirect('/admin/notifications')->with('notification_retried', true);
    }
}
