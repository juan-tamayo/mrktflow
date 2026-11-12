<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ListStoreExports
{
    /**
     * @param  array<string, string>  $tokens
     * @return list<array{id: string, dataset: string, status: string, row_count: int, expires_at: string, download_url: ?string}>
     */
    public function list(StaffAccount $actor, array $tokens): array
    {
        if (! $actor->hasPermission('exports.read')) {
            throw new AuthorizationException;
        }
        $rows = DB::table('export_jobs')->where('staff_id', $actor->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        $jobs = [];
        foreach ($rows as $row) {
            $id = (string) $row->id;
            $token = $tokens[$id] ?? null;
            $ready = (string) $row->status === 'ready' && now()->lt($row->expires_at);
            $download = is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1 && $ready
                ? '/admin/exports/'.$id.'/download?token='.$token
                : null;
            $expires = $row->expires_at;
            $jobs[] = [
                'id' => $id,
                'dataset' => (string) $row->dataset,
                'status' => (string) $row->status,
                'row_count' => (int) $row->row_count,
                'expires_at' => is_string($expires) ? $expires : (string) $expires,
                'download_url' => $download,
            ];
        }

        return $jobs;
    }
}
