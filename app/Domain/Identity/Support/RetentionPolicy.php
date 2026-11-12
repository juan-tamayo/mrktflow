<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use Illuminate\Support\Facades\DB;

final class RetentionPolicy
{
    public const DEFAULT_GUEST_CART_DAYS = 30;

    public const DEFAULT_AI_PROMPT_DAYS = 30;

    public const EXPORT_HOURS = 24;

    public function guestCartsDays(): int
    {
        return $this->positiveDays('guest_carts_days', self::DEFAULT_GUEST_CART_DAYS);
    }

    public function aiPromptLogsDays(): int
    {
        return $this->positiveDays('ai_prompt_logs_days', self::DEFAULT_AI_PROMPT_DAYS);
    }

    public function orderDays(): ?int
    {
        $days = $this->retention()['order_days'] ?? null;
        if (! is_int($days) && ! is_string($days)) {
            return null;
        }
        $value = (int) $days;

        return $value >= 1 && $value <= 36500 ? $value : null;
    }

    /** @return array{guest_carts_days: int, ai_prompt_logs_days: int, order_days: ?int} */
    public function snapshot(): array
    {
        return [
            'guest_carts_days' => $this->guestCartsDays(),
            'ai_prompt_logs_days' => $this->aiPromptLogsDays(),
            'order_days' => $this->orderDays(),
        ];
    }

    private function positiveDays(string $key, int $default): int
    {
        $days = $this->retention()[$key] ?? $default;
        if (! is_int($days) && ! is_string($days)) {
            return $default;
        }
        $value = (int) $days;

        return $value >= 1 && $value <= 36500 ? $value : $default;
    }

    /** @return array<string, mixed> */
    private function retention(): array
    {
        $raw = DB::table('store_settings')->where('id', 1)->value('values');
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }
        $retention = $decoded['retention'] ?? null;

        return is_array($retention) ? $retention : [];
    }
}
