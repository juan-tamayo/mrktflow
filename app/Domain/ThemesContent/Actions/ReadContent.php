<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Staff listings for pages, articles and menus (THEME-02, T065).
 */
final class ReadContent
{
    /**
     * @return list<array{id: string, title: string, slug: string, path: string, status: string, version: int, published_revision: int}>
     */
    public function pages(): array
    {
        $rows = DB::table('pages')->orderBy('title')->orderBy('id')->get();
        $pages = [];
        foreach ($rows as $row) {
            $pages[] = [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'slug' => (string) $row->slug,
                'path' => (string) $row->path,
                'status' => (string) $row->status,
                'version' => (int) $row->version,
                'published_revision' => $row->published_revision === null ? 0 : (int) $row->published_revision,
            ];
        }

        return $pages;
    }

    /**
     * @return list<array{id: string, title: string, slug: string, path: string, excerpt: string, status: string, version: int, published_revision: int}>
     */
    public function articles(): array
    {
        $rows = DB::table('articles')->orderByDesc('published_at')->orderBy('title')->get();
        $articles = [];
        foreach ($rows as $row) {
            $articles[] = [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'slug' => (string) $row->slug,
                'path' => (string) $row->path,
                'excerpt' => (string) $row->excerpt,
                'status' => (string) $row->status,
                'version' => (int) $row->version,
                'published_revision' => $row->published_revision === null ? 0 : (int) $row->published_revision,
            ];
        }

        return $articles;
    }

    /**
     * @return list<array{id: string, handle: string, title: string, version: int, items: list<array{label: string, url: string}>}>
     */
    public function menus(): array
    {
        $rows = DB::table('menus')->orderBy('handle')->get();
        $menus = [];
        foreach ($rows as $row) {
            $items = [];
            $itemRows = DB::table('menu_items')->where('menu_id', $row->id)->orderBy('position')->get(['label', 'url']);
            foreach ($itemRows as $item) {
                $items[] = ['label' => (string) $item->label, 'url' => (string) $item->url];
            }
            $menus[] = [
                'id' => (string) $row->id,
                'handle' => (string) $row->handle,
                'title' => (string) $row->title,
                'version' => (int) $row->version,
                'items' => $items,
            ];
        }

        return $menus;
    }
}
