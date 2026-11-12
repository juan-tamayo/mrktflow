<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\Identity\Actions\DownloadPrivacyExport;
use App\Domain\Identity\Actions\RequestPrivacy;
use App\Domain\Identity\Models\CustomerAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CustomerPrivacyController
{
    public function show(Request $request): View
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof CustomerAccount, 403);
        $requests = [];
        foreach (DB::table('privacy_requests')->where('customer_id', $customer->id)->orderByDesc('created_at')->orderByDesc('id')->get() as $row) {
            $download = (string) $row->type === 'export' && (string) $row->status === 'completed'
                && $row->storage_key !== null && $row->export_expires_at !== null && now()->lt($row->export_expires_at);
            $requests[] = [
                'id' => (string) $row->id,
                'type' => (string) $row->type,
                'status' => (string) $row->status,
                'download' => $download,
            ];
        }

        return view('system.customer-privacy', ['requests' => $requests]);
    }

    public function store(Request $request, RequestPrivacy $privacy): RedirectResponse
    {
        $actor = $request->user('customer');
        abort_unless($actor instanceof CustomerAccount, 403);
        $input = $request->validate(['privacy' => ['required', 'array:type,current_password']]);
        $payload = $input['privacy'];
        if (! is_array($payload)) {
            throw ValidationException::withMessages(['privacy' => 'A privacy request is required.'])->redirectTo('/account/privacy');
        }
        try {
            $privacy->request($actor, $payload);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/account/privacy');
        }

        return redirect('/account/privacy')->with('status', 'Your verified privacy request is recorded.');
    }

    public function download(Request $request, string $requestId, DownloadPrivacyExport $downloads): StreamedResponse
    {
        $actor = $request->user('customer');
        abort_unless($actor instanceof CustomerAccount, 403);
        $payload = $downloads->download($actor, $requestId);

        return response()->streamDownload(function () use ($payload): void {
            echo $payload['csv'];
        }, $payload['filename'], $payload['headers']);
    }
}
