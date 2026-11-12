<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use App\Domain\ThemesContent\Support\ContentRevisions;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Published article summaries for the article-list section (THEME-02, T065).
 *
 * Drafts are omitted. An empty result is a valid storefront state — the
 * section renders its empty copy instead of inventing guides.
 */
final class ListArticles
{
    /**
     * @return list<array{title: string, url: string, excerpt: string}>
     */
    public function published(int $limit): array
    {
        $cap = max(1, min($limit, 24));
        $rows = DB::table('articles')
            ->join('content_revisions', function (JoinClause $join): void {
                $join->on('content_revisions.subject_id', '=', 'articles.id')
                    ->where('content_revisions.subject_type', '=', ContentRevisions::ARTICLE)
                    ->whereColumn('content_revisions.revision', 'articles.published_revision');
            })
            ->whereNotNull('articles.published_revision')
            ->orderByDesc('articles.published_at')
            ->orderBy('articles.id')
            ->limit($cap)
            ->get(['content_revisions.payload']);
        $articles = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                continue;
            }
            $articles[] = [
                'title' => (string) ($payload['title'] ?? ''),
                'url' => '/'.(string) ($payload['path'] ?? ''),
                'excerpt' => (string) ($payload['excerpt'] ?? ''),
            ];
        }

        return $articles;
    }
}
