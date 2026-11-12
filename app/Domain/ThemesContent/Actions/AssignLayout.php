<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\ThemesContent\Support\VisualTemplates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Assigns a visual template independently of product schemas (THEME-02, T066).
 *
 * The assignment stores only a subject and template key. Live prices and
 * typed attributes stay on the catalog; switching layout cannot rewrite them.
 */
final class AssignLayout
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, subject_type: string, subject_id: string, template_key: string}
     */
    public function assign(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('content.write')) {
            throw new AuthorizationException;
        }
        $data = Validator::make(['layout' => $input], [
            'layout' => ['required', 'array:subject_type,subject_id,template_key'],
            'layout.subject_type' => ['required', 'string', Rule::in(VisualTemplates::subjects())],
            'layout.subject_id' => ['required', 'uuid'],
            'layout.template_key' => ['required', 'string', 'max:80'],
        ])->validate()['layout'];
        $subjectType = (string) $data['subject_type'];
        $templateKey = (string) $data['template_key'];
        if (! VisualTemplates::allows($subjectType, $templateKey)) {
            throw ValidationException::withMessages([
                'template_key' => 'Choose a visual template that belongs to this '.$subjectType.'.',
            ]);
        }
        $this->assertSubjectExists($subjectType, (string) $data['subject_id']);

        return DB::transaction(function () use ($actor, $data, $subjectType, $templateKey): array {
            $now = now();
            $subjectId = (string) $data['subject_id'];
            $existing = DB::table('layout_assignments')
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->lockForUpdate()
                ->first();
            $id = $existing === null ? (string) Str::uuid() : (string) $existing->id;
            $row = [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'template_key' => $templateKey,
                'updated_at' => $now,
            ];
            if ($existing === null) {
                DB::table('layout_assignments')->insert($row + ['id' => $id, 'created_at' => $now]);
            } else {
                DB::table('layout_assignments')->where('id', $id)->update($row);
            }
            app(RecordEvent::class)->record('platform.audit.recorded', 'content:layout:'.$id, [
                'action' => 'content.layout.assigned',
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['template_key' => $templateKey],
            ]);

            return [
                'id' => $id,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'template_key' => $templateKey,
            ];
        });
    }

    private function assertSubjectExists(string $subjectType, string $subjectId): void
    {
        $table = match ($subjectType) {
            'product' => 'products',
            'category' => 'categories',
            'page' => 'pages',
            default => throw ValidationException::withMessages(['subject_type' => 'Unknown layout subject.']),
        };
        if (! DB::table($table)->where('id', $subjectId)->exists()) {
            throw ValidationException::withMessages(['subject_id' => 'This '.$subjectType.' is not available.']);
        }
    }
}
