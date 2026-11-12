<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Actions;

use App\Domain\AiAuthoring\Data\UsageHold;
use App\Domain\AiAuthoring\Data\UsageReceipt;
use App\Domain\AiAuthoring\Exceptions\AiAuthoringDisabled;
use App\Domain\AiAuthoring\Exceptions\AiAuthoringRejected;
use App\Domain\AiAuthoring\Exceptions\AiBudgetExhausted;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ReserveUsage
{
    public function reserve(StaffAccount $actor, UsageHold $hold): UsageReceipt
    {
        if ($actor->id !== $hold->staffId || ! $actor->hasPermission('ai.use')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $hold): UsageReceipt {
            $settings = DB::table('ai_settings')->where('id', 1)->lockForUpdate()->first();
            if ($settings === null || ! (bool) $settings->enabled) {
                throw new AiAuthoringDisabled;
            }
            if (DB::table('ai_authors')->where('staff_id', $actor->id)->lockForUpdate()->first() === null) {
                throw new AuthorizationException;
            }
            $hash = $this->hash('reserve', $hold);
            if ($this->claim($hold->operationKey, $hold->intentId, 'reserve', $hash)) {
                return $this->receipt($hold->intentId);
            }
            $day = now()->utc()->toDateString();
            $month = now()->utc()->format('Y-m');
            $daily = $this->charged($day, null) + $hold->estimatedTokens;
            $monthly = $this->charged(null, $month) + $hold->estimatedTokens;
            if ($daily > (int) $settings->daily_token_budget || $monthly > (int) $settings->monthly_token_budget) {
                throw new AiBudgetExhausted;
            }
            $id = (string) Str::uuid();
            $now = now();
            try {
                DB::table('ai_usage_reservations')->insert([
                    'id' => $id,
                    'intent_id' => $hold->intentId,
                    'staff_id' => $hold->staffId,
                    'estimated_tokens' => $hold->estimatedTokens,
                    'charged_tokens' => null,
                    'status' => 'reserved',
                    'period_day' => $day,
                    'period_month' => $month,
                    'expires_at' => $hold->expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new HttpException(409, 'This AI request intent is already reserved.');
            }

            return new UsageReceipt($hold->intentId, $id, 'reserved');
        });
    }

    public function record(StaffAccount $actor, string $intentId, string $operationKey, ?int $inputTokens, ?int $outputTokens): UsageReceipt
    {
        $this->assertIds($intentId, $operationKey);
        if ($inputTokens !== null && ($inputTokens < 0 || $inputTokens > 1000000000)) {
            throw new InvalidArgumentException('Invalid usage measurement.');
        }
        if ($outputTokens !== null && ($outputTokens < 0 || $outputTokens > 1000000000)) {
            throw new InvalidArgumentException('Invalid usage measurement.');
        }
        $known = $inputTokens !== null && $outputTokens !== null;
        if (($inputTokens === null) !== ($outputTokens === null)) {
            throw new InvalidArgumentException('Invalid usage measurement.');
        }

        return DB::transaction(function () use ($actor, $intentId, $operationKey, $inputTokens, $outputTokens, $known): UsageReceipt {
            $this->assertAuthor($actor);
            $reservation = $this->lockReservation($intentId);
            $hash = hash('sha256', json_encode([
                'action' => 'record',
                'intent_id' => $intentId,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
            ], JSON_THROW_ON_ERROR));
            if ($this->claim($operationKey, $intentId, 'record', $hash)) {
                return $this->receipt($intentId);
            }
            $status = $this->string($reservation, 'status');
            if (! in_array($status, ['reserved', 'pending_unknown'], true)) {
                throw new AiAuthoringRejected;
            }
            $now = now();
            $total = $known ? $inputTokens + $outputTokens : null;
            DB::table('ai_usage_reservations')->where('id', $this->string($reservation, 'id'))->update([
                'status' => $known ? 'committed' : 'pending_unknown',
                'charged_tokens' => $total,
                'updated_at' => $now,
            ]);
            $existing = DB::table('ai_usage_entries')->where('intent_id', $intentId)->lockForUpdate()->first();
            if ($existing === null) {
                DB::table('ai_usage_entries')->insert([
                    'id' => (string) Str::uuid(),
                    'reservation_id' => $this->string($reservation, 'id'),
                    'intent_id' => $intentId,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'total_tokens' => $total,
                    'usage_known' => $known,
                    'created_at' => $now,
                ]);
            } else {
                DB::table('ai_usage_entries')->where('id', $this->string($existing, 'id'))->update([
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'total_tokens' => $total,
                    'usage_known' => $known,
                ]);
            }

            return $this->receipt($intentId);
        });
    }

    public function release(StaffAccount $actor, string $intentId, string $operationKey): UsageReceipt
    {
        $this->assertIds($intentId, $operationKey);

        return DB::transaction(function () use ($actor, $intentId, $operationKey): UsageReceipt {
            $this->assertAuthor($actor);
            $reservation = $this->lockReservation($intentId);
            $hash = hash('sha256', json_encode(['action' => 'release', 'intent_id' => $intentId], JSON_THROW_ON_ERROR));
            if ($this->claim($operationKey, $intentId, 'release', $hash)) {
                return $this->receipt($intentId);
            }
            if ($this->string($reservation, 'status') !== 'reserved') {
                throw new AiAuthoringRejected;
            }
            DB::table('ai_usage_reservations')->where('id', $this->string($reservation, 'id'))->update([
                'status' => 'released',
                'charged_tokens' => 0,
                'expires_at' => null,
                'updated_at' => now(),
            ]);

            return $this->receipt($intentId);
        });
    }

    private function charged(?string $day, ?string $month): int
    {
        $query = DB::table('ai_usage_reservations')->whereIn('status', ['reserved', 'pending_unknown', 'committed']);
        if ($day !== null) {
            $query->where('period_day', $day);
        }
        if ($month !== null) {
            $query->where('period_month', $month);
        }
        $rows = $query->lockForUpdate()->get();
        $total = 0;
        foreach ($rows as $row) {
            $status = $this->string($row, 'status');
            if ($status === 'committed') {
                $total += (int) $this->string($row, 'charged_tokens');
            } else {
                $total += (int) $this->string($row, 'estimated_tokens');
            }
        }

        return $total;
    }

    private function claim(string $operationKey, string $intentId, string $action, string $hash): bool
    {
        try {
            DB::table('ai_usage_operations')->insert([
                'operation_key' => $operationKey,
                'intent_id' => $intentId,
                'action' => $action,
                'payload_hash' => $hash,
                'created_at' => now(),
            ]);

            return false;
        } catch (UniqueConstraintViolationException) {
            $existing = DB::table('ai_usage_operations')->where('operation_key', $operationKey)->lockForUpdate()->first();
            if ($existing === null
                || $this->string($existing, 'intent_id') !== $intentId
                || $this->string($existing, 'action') !== $action
                || $this->string($existing, 'payload_hash') !== $hash) {
                throw new HttpException(409, 'This AI usage operation conflicts with an earlier request.');
            }

            return true;
        }
    }

    private function hash(string $action, UsageHold $hold): string
    {
        return hash('sha256', json_encode([
            'action' => $action,
            'intent_id' => $hold->intentId,
            'staff_id' => $hold->staffId,
            'estimated_tokens' => $hold->estimatedTokens,
        ], JSON_THROW_ON_ERROR));
    }

    private function receipt(string $intentId): UsageReceipt
    {
        $hold = DB::table('ai_usage_reservations')->where('intent_id', $intentId)->first();
        if ($hold === null) {
            throw new AiAuthoringRejected;
        }

        return new UsageReceipt($intentId, $this->string($hold, 'id'), $this->string($hold, 'status'));
    }

    private function lockReservation(string $intentId): object
    {
        DB::table('ai_settings')->where('id', 1)->lockForUpdate()->first();
        $reservation = DB::table('ai_usage_reservations')->where('intent_id', $intentId)->lockForUpdate()->first();
        if ($reservation === null) {
            throw new AiAuthoringRejected;
        }

        return $reservation;
    }

    private function assertAuthor(StaffAccount $actor): void
    {
        if (! $actor->hasPermission('ai.use')
            || DB::table('ai_authors')->where('staff_id', $actor->id)->lockForUpdate()->first() === null) {
            throw new AuthorizationException;
        }
    }

    private function assertIds(string $intentId, string $operationKey): void
    {
        if (! Str::isUuid($intentId) || $intentId !== strtolower($intentId)
            || ! Str::isUuid($operationKey) || $operationKey !== strtolower($operationKey)) {
            throw new InvalidArgumentException('Invalid usage mutation.');
        }
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
