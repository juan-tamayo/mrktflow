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
 * Atomically switches a validated draft revision to live (THEME-03, T067).
 *
 * Saves never move the published pointer. A stale expected version conflicts
 * without changing live content. Section drafts re-run ValidateSections so a
 * binding that stopped being published cannot go live.
 */
final class PublishContent
{
    public function __construct(
        private readonly ValidateSections $validate,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{kind: string, id: string, version: int, published_revision: int}
     */
    public function publish(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('content.publish')) {
            throw new AuthorizationException;
        }
        $data = Validator::make($input, [
            'kind' => ['required', Rule::in(['page', 'article', 'section_draft'])],
            'id' => ['required_unless:kind,section_draft', 'nullable', 'uuid'],
            'page' => ['required_if:kind,section_draft', 'nullable', 'string', 'max:64'],
            'slot' => ['required_if:kind,section_draft', 'nullable', 'string', 'max:64'],
            'version' => ['required', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data): array {
            return match ($data['kind']) {
                'page' => $this->publishDocument($actor, 'pages', ContentRevisions::PAGE, (string) $data['id'], (int) $data['version']),
                'article' => $this->publishDocument($actor, 'articles', ContentRevisions::ARTICLE, (string) $data['id'], (int) $data['version']),
                default => $this->publishSectionDraft($actor, (string) $data['page'], (string) $data['slot'], (int) $data['version']),
            };
        });
    }

    /**
     * @return array{kind: string, id: string, version: int, published_revision: int}
     */
    private function publishDocument(StaffAccount $actor, string $table, string $kind, string $id, int $expected): array
    {
        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row !== null, 404);
        abort_unless((int) $row->version === $expected, 409, 'This '.$kind.' changed. Reload before publishing.');
        $payload = ContentRevisions::payload($kind, $id, (int) $row->draft_revision);
        abort_unless(is_array($payload), 404);
        $previousPath = null;
        if ($row->published_revision !== null) {
            $previous = ContentRevisions::payload($kind, $id, (int) $row->published_revision);
            if (is_array($previous)) {
                $previousPath = (string) ($previous['path'] ?? '');
            }
        }
        $now = now();
        DB::table($table)->where('id', $id)->update([
            'status' => 'published',
            'published_revision' => (int) $row->draft_revision,
            'published_at' => $row->published_at ?? $now,
            'updated_at' => $now,
        ]);
        app(RecordEvent::class)->record('platform.audit.recorded', 'content:'.$kind.':'.$id.':publish:'.$row->draft_revision, [
            'action' => 'content.'.$kind.'.published',
            'subject_type' => $kind,
            'subject_id' => $id,
            'actor_type' => 'staff',
            'actor_id' => $actor->id,
            'metadata' => ['path' => (string) ($payload['path'] ?? ''), 'version' => (int) $row->version, 'published_revision' => (int) $row->draft_revision],
        ]);
        app(SaveRedirect::class)->recordPublishedPathChange($previousPath, (string) ($payload['path'] ?? ''));

        return ['kind' => $kind, 'id' => $id, 'version' => (int) $row->version, 'published_revision' => (int) $row->draft_revision];
    }

    /**
     * @return array{kind: string, id: string, version: int, published_revision: int}
     */
    private function publishSectionDraft(StaffAccount $actor, string $page, string $slot, int $expected): array
    {
        if (! SectionDraftTargets::isEditable($page, $slot)) {
            throw new InvalidArgumentException("Page [{$page}] has no editable section slot [{$slot}].");
        }
        $row = DB::table('content_drafts')->where('page_key', $page)->where('slot', $slot)->lockForUpdate()->first();
        abort_unless($row !== null, 404);
        abort_unless((int) $row->version === $expected, 409, 'This draft changed. Reload before publishing.');
        $decoded = json_decode((string) $row->sections, true, flags: JSON_THROW_ON_ERROR);
        $sections = is_array($decoded) ? $decoded : [];
        $this->validate->validate($sections);
        DB::table('content_drafts')->where('id', $row->id)->update([
            'published_revision' => (int) $row->version,
            'updated_at' => now(),
        ]);
        $subject = ContentRevisions::sectionSubject($page, $slot);
        app(RecordEvent::class)->record('platform.audit.recorded', 'content:section_draft:'.$subject.':publish:'.$row->version, [
            'action' => 'content.section_draft.published',
            'subject_type' => 'content_draft',
            'subject_id' => $subject,
            'actor_type' => 'staff',
            'actor_id' => $actor->id,
            'metadata' => ['page' => $page, 'slot' => $slot, 'version' => (int) $row->version, 'published_revision' => (int) $row->version],
        ]);

        return ['kind' => 'section_draft', 'id' => $subject, 'version' => (int) $row->version, 'published_revision' => (int) $row->version];
    }
}
