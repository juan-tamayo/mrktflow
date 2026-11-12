<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use App\Domain\ThemesContent\Support\ContentRevisions;
use Illuminate\Support\Facades\DB;

/**
 * Reads the live published revision for pages, articles and section drafts (THEME-03, T067).
 *
 * Draft saves never appear here. Missing publication is an empty result, not invented copy.
 */
final class ReadPublishedContent
{
    /**
     * @return array{revision: int, title: string, slug: string, path: string}
     */
    public function page(string $id): array
    {
        $document = $this->document('pages', ContentRevisions::PAGE, $id);
        unset($document['excerpt']);

        return $document;
    }

    /**
     * @return array{revision: int, title: string, slug: string, path: string, excerpt: string}
     */
    public function article(string $id): array
    {
        $document = $this->document('articles', ContentRevisions::ARTICLE, $id);

        return $document + ['excerpt' => (string) ($document['excerpt'] ?? '')];
    }

    /**
     * @return array{revision: int, page: string, slot: string, sections: list<array<string, mixed>>}
     */
    public function sectionDraft(string $page, string $slot): array
    {
        $row = DB::table('content_drafts')->where('page_key', $page)->where('slot', $slot)->first();
        abort_unless($row !== null && $row->published_revision !== null, 404);
        $payload = ContentRevisions::payload(ContentRevisions::SECTION_DRAFT, ContentRevisions::sectionSubject($page, $slot), (int) $row->published_revision);
        abort_unless(is_array($payload) && isset($payload['sections']) && is_array($payload['sections']), 404);
        $sections = [];
        foreach ($payload['sections'] as $section) {
            if (is_array($section)) {
                $sections[] = $section;
            }
        }

        return [
            'revision' => (int) $row->published_revision,
            'page' => $page,
            'slot' => $slot,
            'sections' => $sections,
        ];
    }

    /**
     * @return array{kind: string, id: string, revision: int, title: string, slug: string, path: string, excerpt: string}
     */
    public function byPath(string $requestPath): array
    {
        $normalized = '/'.ltrim($requestPath, '/');
        if (preg_match('#^/pages/[a-z0-9]+(?:-[a-z0-9]+)*$#D', $normalized) === 1) {
            return $this->publishedMatching('pages', ContentRevisions::PAGE, 'page', ltrim($normalized, '/'));
        }
        if (preg_match('#^/journal/[a-z0-9]+(?:-[a-z0-9]+)*$#D', $normalized) === 1) {
            return $this->publishedMatching('articles', ContentRevisions::ARTICLE, 'article', ltrim($normalized, '/'));
        }
        abort(404);
    }

    /**
     * @return array{kind: string, id: string, revision: int, title: string, slug: string, path: string, excerpt: string}
     */
    private function publishedMatching(string $table, string $subjectType, string $kind, string $documentPath): array
    {
        $rows = DB::table($table)->whereNotNull('published_revision')->orderBy('id')->get();
        foreach ($rows as $row) {
            $payload = ContentRevisions::payload($subjectType, (string) $row->id, (int) $row->published_revision);
            if (! is_array($payload) || (string) ($payload['path'] ?? '') !== $documentPath) {
                continue;
            }

            return [
                'kind' => $kind,
                'id' => (string) $row->id,
                'revision' => (int) $row->published_revision,
                'title' => (string) ($payload['title'] ?? ''),
                'slug' => (string) ($payload['slug'] ?? ''),
                'path' => (string) ($payload['path'] ?? ''),
                'excerpt' => (string) ($payload['excerpt'] ?? ''),
            ];
        }
        abort(404);
    }

    /**
     * @return array{revision: int, title: string, slug: string, path: string, excerpt?: string}
     */
    private function document(string $table, string $subjectType, string $id): array
    {
        $row = DB::table($table)->where('id', $id)->first();
        abort_unless($row !== null && $row->published_revision !== null, 404);
        $payload = ContentRevisions::payload($subjectType, $id, (int) $row->published_revision);
        abort_unless(is_array($payload), 404);

        return [
            'revision' => (int) $row->published_revision,
            'title' => (string) ($payload['title'] ?? ''),
            'slug' => (string) ($payload['slug'] ?? ''),
            'path' => (string) ($payload['path'] ?? ''),
            'excerpt' => (string) ($payload['excerpt'] ?? ''),
        ];
    }
}
