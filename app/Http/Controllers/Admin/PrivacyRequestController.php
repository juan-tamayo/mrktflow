<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Actions\ListPrivacyRequests;
use App\Domain\Identity\Actions\ProcessPrivacyRequest;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class PrivacyRequestController
{
    public function index(Request $request, ListPrivacyRequests $requests): Response
    {
        Gate::authorize('customers.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $payload = $requests->list($staff);

        return Inertia::render('PrivacyRequests', [
            'requests' => $payload['requests'],
            'retention' => $payload['retention'],
            'processed' => session()->get('privacy_processed', false),
        ]);
    }

    public function process(Request $request, string $requestId, ProcessPrivacyRequest $processor): RedirectResponse
    {
        Gate::authorize('customers.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $processor->process($staff, $requestId);

        return redirect('/admin/privacy')->with('privacy_processed', true);
    }
}
