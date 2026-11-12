<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Support\NotificationCatalog;
use Illuminate\Support\Facades\DB;

final class ListNotificationFailures
{
    /**
     * @return array{exactlyOnce: false, disclaimer: string, failures: list<array{id: string, template: string, policyClass: string, status: string, errorCode: string|null, attempts: int, recipient: string, availableAt: string|null}>}
     */
    public function page(): array
    {
        $failures = [];
        $rows = DB::table('notification_deliveries')
            ->where(function ($query): void {
                $query->where('status', 'failed')
                    ->orWhere(function ($pending): void {
                        $pending->where('status', 'pending')->whereNotNull('last_error_code');
                    });
            })
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit(50)
            ->get();
        foreach ($rows as $row) {
            $failures[] = [
                'id' => (string) $row->id,
                'template' => (string) $row->template,
                'policyClass' => (string) $row->policy_class,
                'status' => (string) $row->status,
                'errorCode' => is_string($row->last_error_code) ? $row->last_error_code : null,
                'attempts' => (int) $row->attempts,
                'recipient' => (string) $row->recipient,
                'availableAt' => is_string($row->available_at) ? $row->available_at : null,
            ];
        }

        return [
            'exactlyOnce' => false,
            'disclaimer' => NotificationCatalog::DISCLAIMER,
            'failures' => $failures,
        ];
    }
}
