<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\ThemesContent\Exceptions\SectionDraftConflict;
use App\Domain\ThemesContent\Support\SectionDraftTargets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Saves one section draft document (THEME-02, T064a).
 *
 * The payload is validated through T063's ValidateSections before
 * anything persists — an invalid payload throws SectionValidationFailed
 * and writes nothing. A valid save stores the normalized payload with
 * binding references only (the `resolved` public summaries the validator
 * derives are render-time data, never document state), increments the
 * document version, and refuses a stale expected version with a
 * SectionDraftConflict carrying the current state. One platform audit
 * records the save; payloads themselves are never audited. Revisions and
 * publication are T067 — this is the single working draft per target.
 */
final class SaveSectionDraft
{
    public function __construct(
        private readonly ValidateSections $validate,
    ) {}

    /**
     * @param  list<mixed>  $sections
     * @return array{version: int, sections: list<array<string, mixed>>}
     */
    public function save(StaffAccount $actor, string $page, string $slot, ?int $expectedVersion, array $sections): array
    {
        if (! SectionDraftTargets::isEditable($page, $slot)) {
            throw new InvalidArgumentException("Page [{$page}] has no editable section slot [{$slot}].");
        }
        $stored = [];
        foreach ($this->validate->validate($sections) as $section) {
            $stored[] = $this->storable($section);
        }
        $expected = $expectedVersion ?? 0;

        return DB::transaction(function () use ($actor, $page, $slot, $expected, $stored): array {
            $row = DB::table('content_drafts')->where('page_key', $page)->where('slot', $slot)->lockForUpdate()->first();
            $current = $row === null ? 0 : (int) $row->version;
            if ($current !== $expected) {
                $decoded = $row === null ? [] : json_decode((string) $row->sections, true, flags: JSON_THROW_ON_ERROR);
                $currentSections = [];
                if (is_array($decoded)) {
                    foreach ($decoded as $item) {
                        if (is_array($item)) {
                            $currentSections[] = $item;
                        }
                    }
                }

                throw new SectionDraftConflict($current, $currentSections);
            }
            $version = $current + 1;
            $values = [
                'sections' => json_encode($stored, JSON_THROW_ON_ERROR),
                'version' => $version,
                'updated_by' => $actor->id,
                'updated_at' => now(),
            ];
            if ($row === null) {
                DB::table('content_drafts')->insert($values + [
                    'id' => (string) Str::uuid(),
                    'page_key' => $page,
                    'slot' => $slot,
                    'created_at' => now(),
                ]);
            } else {
                DB::table('content_drafts')->where('id', $row->id)->update($values);
            }
            app(RecordEvent::class)->record('platform.audit.recorded', "audit:content-draft:{$page}:{$slot}:{$version}", [
                'action' => 'content.draft.saved',
                'subject_type' => 'content_draft',
                'subject_id' => "{$page}:{$slot}",
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['page' => $page, 'slot' => $slot, 'version' => $version, 'section_count' => count($stored)],
            ]);

            return ['version' => $version, 'sections' => $stored];
        });
    }

    /**
     * The document form of a validated section: binding references only,
     * and no `binding` key at all when the block carries none — a stored
     * payload must re-validate exactly as the editor submitted it.
     *
     * @param  array{id: string, type: string, version: int, settings: array<string, mixed>, binding: array<string, mixed>|null}  $section
     * @return array<string, mixed>
     */
    private function storable(array $section): array
    {
        $stored = [
            'id' => $section['id'],
            'type' => $section['type'],
            'version' => $section['version'],
            'settings' => $section['settings'],
        ];
        $binding = $section['binding'];
        if ($binding !== null) {
            $stored['binding'] = array_key_exists('publicId', $binding)
                ? ['kind' => $binding['kind'], 'publicId' => $binding['publicId']]
                : ['kind' => $binding['kind'], 'publicIds' => $binding['publicIds']];
        }

        return $stored;
    }
}
