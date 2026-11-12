<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

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
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->orderBy('id')
            ->limit($cap)
            ->get(['title', 'path', 'excerpt']);
        $articles = [];
        foreach ($rows as $row) {
            $articles[] = [
                'title' => (string) $row->title,
                'url' => '/'.$row->path,
                'excerpt' => (string) $row->excerpt,
            ];
        }

        return $articles;
    }
}
