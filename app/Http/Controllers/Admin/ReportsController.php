<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Operations\Actions\BuildSalesReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ReportsController
{
    public function show(Request $request, BuildSalesReport $reports): Response
    {
        Gate::authorize('reports.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return Inertia::render('Reports', [
            'report' => $reports->build($staff, [
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ]),
        ]);
    }
}
