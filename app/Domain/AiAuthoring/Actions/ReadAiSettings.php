<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Actions;

use App\Domain\AiAuthoring\Support\AiModels;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Support\Facades\DB;

final class ReadAiSettings
{
    /**
     * @return array{
     *     enabled: bool,
     *     provider: string,
     *     model: string,
     *     models: list<string>,
     *     daily_token_budget: int,
     *     monthly_token_budget: int,
     *     has_secret: bool,
     *     secret_hint: ?string,
     *     author_ids: list<string>,
     *     version: int
     * }
     */
    public function forOwner(): array
    {
        $row = DB::table('ai_settings')->where('id', 1)->first();
        $authors = DB::table('ai_authors')->orderBy('staff_id')->pluck('staff_id')->all();
        $vars = $row !== null ? get_object_vars($row) : [];

        return [
            'enabled' => (bool) ($vars['enabled'] ?? false),
            'provider' => is_string($vars['provider'] ?? null) ? $vars['provider'] : AiModels::PROVIDER,
            'model' => is_string($vars['model'] ?? null) ? $vars['model'] : AiModels::permitted()[0],
            'models' => AiModels::permitted(),
            'daily_token_budget' => (int) ($vars['daily_token_budget'] ?? 0),
            'monthly_token_budget' => (int) ($vars['monthly_token_budget'] ?? 0),
            'has_secret' => is_string($vars['secret_payload'] ?? null),
            'secret_hint' => is_string($vars['secret_hint'] ?? null) ? $vars['secret_hint'] : null,
            'author_ids' => array_values(array_filter($authors, is_string(...))),
            'version' => (int) ($vars['revision'] ?? 1),
        ];
    }

    /**
     * @return list<array{id: string, name: string, role: string}>
     */
    public function authorCandidates(): array
    {
        $staff = StaffAccount::query()->where('is_active', true)->orderBy('name')->get();
        $candidates = [];
        foreach ($staff as $member) {
            if (! $member->hasPermission('ai.use')) {
                continue;
            }
            $candidates[] = ['id' => $member->id, 'name' => $member->name, 'role' => $member->role];
        }

        return $candidates;
    }
}
