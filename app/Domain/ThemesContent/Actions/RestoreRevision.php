<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\ThemesContent\Support\ContentRevisions;
use App\Domain\ThemesContent\Support\SectionDraftTargets;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Restores an earlier snapshot as a new published revision (THEME-03, T068).
 *
 * Rollback never mutates historical rows. It copies the chosen payload
 * into a new draft revision and switches the live pointer to that new
 * revision under the same optimistic version lock as publication.
 */
final class RestoreRevision
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{kind: string, id: string, version: int, published_revision: int, restored_revision: int}
     */
    public function restore(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('content.publish')) {
            throw new AuthorizationException;
        }
        $data = Validator::make($input, [
            'kind' => ['required', Rule::in(['page', 'article', 'section_draft'])],
            'id' => ['required_unless:kind,section_draft', 'nullable', 'uuid'],
            'page' => ['required_if:kind,section_draft', 'nullable', 'string', 'max:64'],
            'slot' => ['required_if:kind,section_draft', 'nullable', 'string', 'max:64'],
            'revision' => ['required', 'integer', 'min:1'],
            'version' => ['required', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data): array {
            return match ($data['kind']) {
                'page' => $this->restoreDocument($actor, 'pages', ContentRevisions::PAGE, (string) $data['id'], (int) $data['revision'], (int) $data['version']),
                'article' => $this->restoreDocument($actor, 'articles', ContentRevisions::ARTICLE, (string) $data['id'], (int) $data['revision'], (int) $data['version']),
                default => $this->restoreSectionDraft($actor, (string) $data['page'], (string) $data['slot'], (int) $data['revision'], (int) $data['version']),
            };
        });
    }

    /**
     * @return array{kind: string, id: string, version: int, published_revision: int, restored_revision: int}
     */
    private function restoreDocument(StaffAccount $actor, string $table, string $kind, string $id, int $revision, int $expected): array
    {
        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row !== null, 404);
        abort_unless((int) $row->version === $expected, 409, 'This '.$kind.' changed. Reload before restoring.');
        abort_unless($row->published_revision !== null, 422, 'Publish this '.$kind.' before restoring a revision.');
        $payload = ContentRevisions::payload($kind, $id, $revision);
        abort_unless(is_array($payload), 404);
        $previousPath = null;
        $previous = ContentRevisions::payload($kind, $id, (int) $row->published_revision);
        if (is_array($previous)) {
            $previousPath = (string) ($previous['path'] ?? '');
        }
        $version = ((int) $row->version) + 1;
        ContentRevisions::record($kind, $id, $version, $payload, $actor->id);
        $now = now();
        $update = [
            'title' => (string) ($payload['title'] ?? $row->title),
            'slug' => (string) ($payload['slug'] ?? $row->slug),
            'path' => (string) ($payload['path'] ?? $row->path),
            'status' => 'published',
            'version' => $version,
            'draft_revision' => $version,
            'published_revision' => $version,
            'updated_by' => $actor->id,
            'updated_at' => $now,
        ];
        if ($table === 'articles') {
            $update['excerpt'] = (string) ($payload['excerpt'] ?? '');
        }
        DB::table($table)->where('id', $id)->update($update);
        app(SaveRedirect::class)->recordPublishedPathChange($previousPath, (string) ($payload['path'] ?? ''));
        app(RecordEvent::class)->record('platform.audit.recorded', 'content:'.$kind.':'.$id.':restore:'.$revision.':'.$version, [
            'action' => 'content.'.$kind.'.restored',
            'subject_type' => $kind,
            'subject_id' => $id,
            'actor_type' => 'staff',
            'actor_id' => $actor->id,
            'metadata' => ['path' => (string) ($payload['path'] ?? ''), 'version' => $version, 'restored_revision' => $revision, 'published_revision' => $version],
        ]);

        return ['kind' => $kind, 'id' => $id, 'version' => $version, 'published_revision' => $version, 'restored_revision' => $revision];
    }

    /**
     * @return array{kind: string, id: string, version: int, published_revision: int, restored_revision: int}
     */
    private function restoreSectionDraft(StaffAccount $actor, string $page, string $slot, int $revision, int $expected): array
    {
        if (! SectionDraftTargets::isEditable($page, $slot)) {
            throw new InvalidArgumentException("Page [{$page}] has no editable section slot [{$slot}].");
        }
        $row = DB::table('content_drafts')->where('page_key', $page)->where('slot', $slot)->lockForUpdate()->first();
        abort_unless($row !== null, 404);
        abort_unless((int) $row->version === $expected, 409, 'This draft changed. Reload before restoring.');
        abort_unless($row->published_revision !== null, 422, 'Publish this draft before restoring a revision.');
        $subject = ContentRevisions::sectionSubject($page, $slot);
        $payload = ContentRevisions::payload(ContentRevisions::SECTION_DRAFT, $subject, $revision);
        abort_unless(is_array($payload) && isset($payload['sections']) && is_array($payload['sections']), 404);
        $version = ((int) $row->version) + 1;
        ContentRevisions::record(ContentRevisions::SECTION_DRAFT, $subject, $version, $payload, $actor->id);
        DB::table('content_drafts')->where('id', $row->id)->update([
            'sections' => json_encode($payload['sections'], JSON_THROW_ON_ERROR),
            'version' => $version,
            'published_revision' => $version,
            'updated_by' => $actor->id,
            'updated_at' => now(),
        ]);
        app(RecordEvent::class)->record('platform.audit.recorded', 'content:section_draft:'.$subject.':restore:'.$revision.':'.$version, [
            'action' => 'content.section_draft.restored',
            'subject_type' => 'content_draft',
            'subject_id' => $subject,
            'actor_type' => 'staff',
            'actor_id' => $actor->id,
            'metadata' => ['page' => $page, 'slot' => $slot, 'version' => $version, 'restored_revision' => $revision, 'published_revision' => $version],
        ]);

        return ['kind' => 'section_draft', 'id' => $subject, 'version' => $version, 'published_revision' => $version, 'restored_revision' => $revision];
    }
}
