<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\RetentionPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ListPrivacyRequests
{
    /**
     * @return array{requests: list<array{id: string, type: string, status: string, email: string, verified_at: string, processed_at: ?string}>, retention: array{guest_carts_days: int, ai_prompt_logs_days: int, order_days: ?int}}
     */
    public function list(StaffAccount $actor): array
    {
        if (! $actor->hasPermission('customers.read')) {
            throw new AuthorizationException;
        }
        $requests = [];
        foreach (DB::table('privacy_requests')->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get() as $row) {
            $verified = $row->verified_at;
            $processed = $row->processed_at;
            $requests[] = [
                'id' => (string) $row->id,
                'type' => (string) $row->type,
                'status' => (string) $row->status,
                'email' => (string) $row->email,
                'verified_at' => is_string($verified) ? $verified : (string) $verified,
                'processed_at' => $processed === null ? null : (is_string($processed) ? $processed : (string) $processed),
            ];
        }

        return [
            'requests' => $requests,
            'retention' => app(RetentionPolicy::class)->snapshot(),
        ];
    }
}
