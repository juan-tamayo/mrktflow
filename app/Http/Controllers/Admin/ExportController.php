<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Operations\Actions\DownloadStoreExport;
use App\Domain\Operations\Actions\ListStoreExports;
use App\Domain\Operations\Actions\QueueStoreExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportController
{
    public function index(Request $request, ListStoreExports $exports): Response
    {
        Gate::authorize('exports.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $tokens = $this->tokens($request);

        return Inertia::render('Exports', [
            'jobs' => $exports->list($staff, $tokens),
            'retentionHours' => QueueStoreExport::RETENTION_HOURS,
            'queued' => session()->get('export_queued', false),
        ]);
    }

    public function store(Request $request, QueueStoreExport $queue): RedirectResponse
    {
        Gate::authorize('exports.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        try {
            $queued = $queue->queue($staff, $request->validate([
                'dataset' => ['required', 'string', 'in:customers,orders,products'],
            ]));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/exports');
        }
        $tokens = $this->tokens($request);
        $tokens[$queued['id']] = $queued['token'];
        $request->session()->put('export_download_tokens', $tokens);

        return redirect('/admin/exports')->with('export_queued', true);
    }

    public function download(Request $request, string $exportId, DownloadStoreExport $downloads): StreamedResponse
    {
        Gate::authorize('exports.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $token = $request->query('token');
        $payload = $downloads->download($staff, $exportId, is_string($token) ? $token : '');

        return response()->streamDownload(function () use ($payload): void {
            echo $payload['csv'];
        }, $payload['filename'], $payload['headers']);
    }

    /** @return array<string, string> */
    private function tokens(Request $request): array
    {
        $stored = $request->session()->get('export_download_tokens', []);
        if (! is_array($stored)) {
            return [];
        }
        $tokens = [];
        foreach ($stored as $id => $token) {
            if (is_string($id) && is_string($token)) {
                $tokens[$id] = $token;
            }
        }

        return $tokens;
    }
}
