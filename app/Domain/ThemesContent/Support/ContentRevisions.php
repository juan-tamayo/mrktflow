<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Immutable content revision snapshots (THEME-03, T067).
 *
 * Draft saves append a revision; publication only switches the
 * published pointer. Payloads are JSON documents, never executable.
 */
final class ContentRevisions
{
    public const PAGE = 'page';

    public const ARTICLE = 'article';

    public const SECTION_DRAFT = 'section_draft';

    public static function sectionSubject(string $page, string $slot): string
    {
        return $page.':'.$slot;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(string $subjectType, string $subjectId, int $revision, array $payload, ?string $actorId): void
    {
        if (! in_array($subjectType, [self::PAGE, self::ARTICLE, self::SECTION_DRAFT], true)) {
            throw new InvalidArgumentException('Unknown content revision subject.');
        }
        if ($revision < 1) {
            throw new InvalidArgumentException('Content revisions start at 1.');
        }
        DB::table('content_revisions')->insert([
            'id' => (string) Str::uuid(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'revision' => $revision,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_by' => $actorId,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function payload(string $subjectType, string $subjectId, int $revision): ?array
    {
        $row = DB::table('content_revisions')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('revision', $revision)
            ->first();
        if ($row === null) {
            return null;
        }
        $decoded = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }
}
