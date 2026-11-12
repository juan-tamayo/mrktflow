<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Operations\Actions\ListAuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AuditLogController
{
    public function show(Request $request, ListAuditLog $audit): Response
    {
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount && $staff->role === 'owner', 403);

        return Inertia::render('AuditLog', [
            'events' => $audit->forOwner($staff),
        ]);
    }
}
