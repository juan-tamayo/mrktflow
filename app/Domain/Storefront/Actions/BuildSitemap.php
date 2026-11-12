<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Actions;

use App\Domain\ThemesContent\Support\ContentRevisions;
use Illuminate\Support\Facades\DB;

/**
 * Public sitemap of live catalog and published content (WEB-05, THEME-05).
 *
 * Drafts, private pages, cart, checkout, account, search and the closed
 * homepage are omitted.
 */
final class BuildSitemap
{
    /** @return list<string> */
    public function urls(): array
    {
        $origin = rtrim(config()->string('app.url'), '/');
        $urls = [];
        foreach (DB::table('products')->where('status', 'published')->orderBy('slug')->pluck('slug') as $slug) {
            if (is_string($slug) && $slug !== '') {
                $urls[] = $origin.'/products/'.$slug;
            }
        }
        foreach (DB::table('categories')->where('status', 'published')->orderBy('path')->get(['path']) as $category) {
            $path = (string) $category->path;
            if ($this->categoryLineagePublished($path)) {
                $urls[] = $origin.'/categories/'.$path;
            }
        }
        foreach ($this->publishedDocumentPaths('pages', ContentRevisions::PAGE) as $path) {
            $urls[] = $origin.'/'.$path;
        }
        foreach ($this->publishedDocumentPaths('articles', ContentRevisions::ARTICLE) as $path) {
            $urls[] = $origin.'/'.$path;
        }

        return array_values(array_unique($urls));
    }

    public function xml(): string
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($this->urls() as $url) {
            $body .= '  <url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>'."\n";
        }

        return $body.'</urlset>'."\n";
    }

    /** @return list<string> */
    private function publishedDocumentPaths(string $table, string $kind): array
    {
        $paths = [];
        $rows = DB::table($table)->whereNotNull('published_revision')->orderBy('slug')->get();
        foreach ($rows as $row) {
            $payload = ContentRevisions::payload($kind, (string) $row->id, (int) $row->published_revision);
            if (! is_array($payload)) {
                continue;
            }
            $path = (string) ($payload['path'] ?? '');
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function categoryLineagePublished(string $path): bool
    {
        $segments = explode('/', $path);
        $prefix = '';
        foreach ($segments as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix.'/'.$segment;
            $status = DB::table('categories')->where('path', $prefix)->value('status');
            if ($status !== 'published') {
                return false;
            }
        }

        return true;
    }
}
