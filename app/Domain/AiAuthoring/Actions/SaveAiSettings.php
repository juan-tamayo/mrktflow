<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Actions;

use App\Domain\AiAuthoring\Support\AiModels;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SaveAiSettings
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{enabled: bool, has_secret: bool, secret_hint: ?string, author_ids: list<string>, revision: int}
     */
    public function save(StaffAccount $actor, array $input): array
    {
        if ($actor->role !== 'owner' || ! $actor->hasPermission('integrations.manage')) {
            throw new AuthorizationException;
        }
        if (($input['api_key'] ?? null) === '') {
            $input['api_key'] = null;
        }
        $data = Validator::make(['settings' => $input], [
            'settings' => ['required', 'array:enabled,provider,model,api_key,daily_token_budget,monthly_token_budget,author_ids,version'],
            'settings.enabled' => ['required', 'boolean'],
            'settings.provider' => ['required', Rule::in([AiModels::PROVIDER])],
            'settings.model' => ['required', Rule::in(AiModels::permitted())],
            'settings.api_key' => ['nullable', 'string', 'min:16', 'max:256', 'regex:/\\A[\\x21-\\x7E]+\\z/'],
            'settings.daily_token_budget' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'settings.monthly_token_budget' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'settings.author_ids' => ['present', 'array', 'max:50'],
            'settings.author_ids.*' => ['uuid'],
            'settings.version' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ])->validate()['settings'];
        if ($data['enabled'] && ((int) $data['daily_token_budget'] < 1 || (int) $data['monthly_token_budget'] < (int) $data['daily_token_budget'])) {
            throw ValidationException::withMessages(['settings' => 'Enabled AI requires a daily token budget within the monthly cap.']);
        }
        $authorIds = array_values(array_unique(array_map(strtolower(...), $data['author_ids'])));

        return DB::transaction(function () use ($actor, $data, $authorIds): array {
            $row = DB::table('ai_settings')->where('id', 1)->lockForUpdate()->first();
            if ($row === null) {
                throw new HttpException(409, 'AI settings are unavailable.');
            }
            if ((int) $row->revision !== (int) $data['version']) {
                throw new HttpException(409, 'AI settings were updated by someone else. Reload and try again.');
            }
            $authors = [];
            if ($authorIds !== []) {
                $staff = StaffAccount::query()->whereIn('id', $authorIds)->lockForUpdate()->get();
                if ($staff->count() !== count($authorIds)) {
                    throw ValidationException::withMessages(['settings.author_ids' => 'Choose current staff accounts as authors.']);
                }
                foreach ($staff as $member) {
                    if (! $member->is_active || ! $member->hasPermission('ai.use')) {
                        throw ValidationException::withMessages(['settings.author_ids' => 'Authors must be active staff with AI permission.']);
                    }
                    $authors[] = $member->id;
                }
            }
            if ($data['enabled'] && $authors === []) {
                throw ValidationException::withMessages(['settings.author_ids' => 'Choose at least one author before enabling AI.']);
            }
            $secret = is_string($data['api_key'] ?? null) ? $data['api_key'] : null;
            $payload = is_string($row->secret_payload) ? $row->secret_payload : null;
            $hint = is_string($row->secret_hint) ? $row->secret_hint : null;
            if ($secret !== null) {
                $payload = Crypt::encryptString(json_encode([
                    'purpose' => 'ai.provider_secret',
                    'secret' => $secret,
                ], JSON_THROW_ON_ERROR));
                $hint = substr($secret, -4);
            }
            if ($data['enabled'] && $payload === null) {
                throw ValidationException::withMessages(['settings.api_key' => 'Add a provider secret before enabling AI.']);
            }
            $revision = (int) $row->revision + 1;
            $now = now();
            DB::table('ai_settings')->where('id', 1)->update([
                'enabled' => $data['enabled'],
                'provider' => $data['provider'],
                'model' => $data['model'],
                'secret_payload' => $payload,
                'secret_hint' => $hint,
                'daily_token_budget' => (int) $data['daily_token_budget'],
                'monthly_token_budget' => (int) $data['monthly_token_budget'],
                'revision' => $revision,
                'updated_at' => $now,
            ]);
            DB::table('ai_authors')->delete();
            foreach ($authors as $staffId) {
                DB::table('ai_authors')->insert(['staff_id' => $staffId, 'created_at' => $now]);
            }
            app(RecordEvent::class)->record('platform.audit.recorded', 'ai:settings:'.$revision, [
                'action' => 'ai.settings_saved',
                'subject_type' => 'ai_settings',
                'subject_id' => '1',
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => [
                    'enabled' => (bool) $data['enabled'],
                    'model' => $data['model'],
                    'has_secret' => $payload !== null,
                    'author_count' => count($authors),
                    'revision' => $revision,
                ],
            ]);

            return [
                'enabled' => (bool) $data['enabled'],
                'has_secret' => $payload !== null,
                'secret_hint' => $hint,
                'author_ids' => $authors,
                'revision' => $revision,
            ];
        });
    }
}
