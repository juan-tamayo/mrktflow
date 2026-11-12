<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Support\CustomerAccountAccess;
use App\Domain\Platform\Support\MediaStoragePolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class DownloadPrivacyExport
{
    /**
     * @return array{filename: string, csv: string, headers: array<string, string>}
     */
    public function download(CustomerAccount $actor, string $requestId): array
    {
        abort_unless(Str::isUuid($requestId) && $requestId === strtolower($requestId), 404);
        app(MediaStoragePolicy::class)->assertPrivate();

        return DB::transaction(function () use ($actor, $requestId): array {
            $customer = app(CustomerAccountAccess::class)->lock($actor);
            app(ProveCustomerIdentity::class)->prove($customer);
            $row = DB::table('privacy_requests')->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($row !== null && hash_equals((string) $row->customer_id, $customer->id), 404);
            abort_unless((string) $row->type === 'export' && (string) $row->status === 'completed', 404);
            abort_unless($row->export_expires_at !== null && now()->lt($row->export_expires_at), 404);
            $key = is_string($row->storage_key) ? $row->storage_key : '';
            abort_unless(preg_match('#^privacy/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.csv$#D', $key) === 1, 404);
            abort_unless(Storage::disk('local')->exists($key), 404);
            $csv = Storage::disk('local')->get($key);
            abort_unless(is_string($csv), 404);

            return [
                'filename' => 'account-export.csv',
                'csv' => $csv,
                'headers' => [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ],
            ];
        });
    }
}
