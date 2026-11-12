<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Operations\Actions\BuildSupportExport;
use App\Domain\Operations\Actions\InspectHealth;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class HealthController
{
    public function show(Request $request, InspectHealth $health): Response
    {
        $staff = $this->owner($request);

        return Inertia::render('SystemHealth', [
            'health' => $health->forOwner($staff),
        ]);
    }

    public function export(Request $request, BuildSupportExport $exports): StreamedResponse
    {
        $staff = $this->owner($request);
        $payload = $exports->forOwner($staff);

        return response()->streamDownload(function () use ($payload): void {
            echo $payload['json'];
        }, $payload['filename'], $payload['headers']);
    }

    private function owner(Request $request): StaffAccount
    {
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount && $staff->role === 'owner', 403);

        return $staff;
    }
}
