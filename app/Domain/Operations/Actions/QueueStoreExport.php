<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Jobs\ExportStoreData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class QueueStoreExport
{
    public const RETENTION_HOURS = 24;

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, token: string, dataset: string, status: string, expires_at: string}
     */
    public function queue(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('exports.read')) {
            throw new AuthorizationException;
        }
        $data = Validator::make($input, [
            'dataset' => ['required', 'string', Rule::in(['customers', 'orders', 'products'])],
        ])->validate();
        $dataset = $data['dataset'];
        if (! is_string($dataset)) {
            throw new InvalidArgumentException('Unknown export dataset.');
        }
        if ($dataset !== 'customers' && $dataset !== 'orders' && $dataset !== 'products') {
            throw new InvalidArgumentException('Unknown export dataset.');
        }
        $ability = match ($dataset) {
            'customers' => 'customers.read',
            'orders' => 'orders.read',
            'products' => 'catalog.read',
        };
        if (! $actor->hasPermission($ability)) {
            throw new AuthorizationException;
        }

        $id = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addHours(self::RETENTION_HOURS);
        DB::transaction(function () use ($actor, $id, $token, $dataset, $expiresAt): void {
            DB::table('export_jobs')->insert([
                'id' => $id,
                'staff_id' => $actor->id,
                'dataset' => $dataset,
                'status' => 'pending',
                'storage_key' => null,
                'token_digest' => hash('sha256', $token),
                'row_count' => 0,
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:export-queued:'.$id, [
                'action' => 'operations.export.queued',
                'subject_type' => 'export_job',
                'subject_id' => $id,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['version' => 1, 'reason_code' => $dataset],
            ]);
        });
        ExportStoreData::dispatch($id);
        $status = DB::table('export_jobs')->where('id', $id)->value('status');

        return [
            'id' => $id,
            'token' => $token,
            'dataset' => $dataset,
            'status' => is_string($status) ? $status : 'pending',
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
