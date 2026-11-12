<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\ThemesContent\Support\ContentRevisions;
use App\Domain\ThemesContent\Support\ReservedContentPaths;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates page, article and menu records with THEME-05 path validation (T065).
 *
 * Slugs cannot collide with reserved storefront routes or the category tree.
 * Menu URLs must be local paths outside administration/installer namespaces.
 * Saves write a new draft revision. Creating with status=published also
 * points the first published revision at that snapshot; later saves keep
 * the live pointer until PublishContent switches it (T067).
 */
final class SaveContent
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, path: string, version: int, status: string}
     */
    public function savePage(StaffAccount $actor, array $input): array
    {
        $this->requireWrite($actor);
        $data = Validator::make(['page' => $input], [
            'page' => ['required', 'array:id,version,title,slug,status'],
            'page.id' => ['nullable', 'uuid'],
            'page.version' => ['required_with:page.id', 'integer', 'min:1'],
            'page.title' => ['required', 'string', 'max:200'],
            'page.slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(ReservedContentPaths::slugs())],
            'page.status' => ['required', Rule::in(['draft', 'published'])],
        ])->validate()['page'];
        $this->assertCategoryTreeFree($data['slug'], 'page.slug');

        try {
            return DB::transaction(function () use ($actor, $data): array {
                return $this->persistDocument($actor, 'pages', 'page', 'pages/'.$data['slug'], $data, null);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['page.slug' => 'This page path is already used.']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, path: string, version: int, status: string, title: string}
     */
    public function saveArticle(StaffAccount $actor, array $input): array
    {
        $this->requireWrite($actor);
        $data = Validator::make(['article' => $input], [
            'article' => ['required', 'array:id,version,title,slug,excerpt,status'],
            'article.id' => ['nullable', 'uuid'],
            'article.version' => ['required_with:article.id', 'integer', 'min:1'],
            'article.title' => ['required', 'string', 'max:200'],
            'article.slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(ReservedContentPaths::slugs())],
            'article.excerpt' => ['nullable', 'string', 'max:500'],
            'article.status' => ['required', Rule::in(['draft', 'published'])],
        ])->validate()['article'];
        $this->assertCategoryTreeFree($data['slug'], 'article.slug');

        try {
            return DB::transaction(function () use ($actor, $data): array {
                $saved = $this->persistDocument($actor, 'articles', 'article', 'journal/'.$data['slug'], $data, (string) ($data['excerpt'] ?? ''));

                return $saved + ['title' => $data['title']];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['article.slug' => 'This article path is already used.']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, handle: string, version: int, item_count: int}
     */
    public function saveMenu(StaffAccount $actor, array $input): array
    {
        $this->requireWrite($actor);
        $data = Validator::make(['menu' => $input], [
            'menu' => ['required', 'array:id,version,handle,title,items'],
            'menu.id' => ['nullable', 'uuid'],
            'menu.version' => ['required_with:menu.id', 'integer', 'min:1'],
            'menu.handle' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9-]{0,31}$/'],
            'menu.title' => ['required', 'string', 'max:120'],
            'menu.items' => ['present', 'array', 'max:30'],
            'menu.items.*.label' => ['required', 'string', 'max:80'],
            'menu.items.*.url' => ['required', 'string', 'max:500'],
        ])->validate()['menu'];
        $items = [];
        foreach (array_values($data['items']) as $index => $item) {
            if (! is_array($item) || ! ReservedContentPaths::isAllowedMenuUrl((string) ($item['url'] ?? ''))) {
                throw ValidationException::withMessages([
                    'menu.items.'.$index.'.url' => 'Enter a local storefront path. Administration, installer and remote URLs are not allowed.',
                ]);
            }
            $items[] = ['label' => $item['label'], 'url' => $item['url']];
        }

        try {
            return DB::transaction(function () use ($actor, $data, $items): array {
                $now = now();
                $existing = null;
                if (isset($data['id'])) {
                    $existing = DB::table('menus')->where('id', $data['id'])->lockForUpdate()->first();
                    abort_unless($existing !== null, 404);
                    abort_unless((int) $existing->version === (int) $data['version'], 409, 'This menu changed. Reload before saving.');
                } else {
                    $existing = DB::table('menus')->where('handle', $data['handle'])->lockForUpdate()->first();
                }
                $id = $existing === null ? (string) Str::uuid() : (string) $existing->id;
                $version = $existing === null ? 1 : ((int) $existing->version) + 1;
                $row = [
                    'handle' => $data['handle'],
                    'title' => $data['title'],
                    'version' => $version,
                    'updated_by' => $actor->id,
                    'updated_at' => $now,
                ];
                if ($existing === null) {
                    DB::table('menus')->insert($row + ['id' => $id, 'created_at' => $now]);
                } else {
                    DB::table('menus')->where('id', $id)->update($row);
                    DB::table('menu_items')->where('menu_id', $id)->delete();
                }
                foreach ($items as $position => $item) {
                    DB::table('menu_items')->insert([
                        'id' => (string) Str::uuid(),
                        'menu_id' => $id,
                        'position' => $position + 1,
                        'label' => $item['label'],
                        'url' => $item['url'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                app(RecordEvent::class)->record('platform.audit.recorded', 'content:menu:'.$id.':'.$version, [
                    'action' => 'content.menu.saved',
                    'subject_type' => 'menu',
                    'subject_id' => $id,
                    'actor_type' => 'staff',
                    'actor_id' => $actor->id,
                    'metadata' => ['handle' => $data['handle'], 'version' => $version, 'item_count' => count($items)],
                ]);

                return ['id' => $id, 'handle' => $data['handle'], 'version' => $version, 'item_count' => count($items)];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['menu.handle' => 'This menu handle is already used.']);
        }
    }

    private function requireWrite(StaffAccount $actor): void
    {
        if (! $actor->hasPermission('content.write')) {
            throw new AuthorizationException;
        }
    }

    private function assertCategoryTreeFree(string $slug, string $key): void
    {
        $collision = DB::table('categories')
            ->where('path', $slug)
            ->orWhere('path', 'like', $slug.'/%')
            ->exists();
        if ($collision) {
            throw ValidationException::withMessages([$key => 'This path collides with the category tree.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{id: string, path: string, version: int, status: string}
     */
    private function persistDocument(StaffAccount $actor, string $table, string $kind, string $path, array $data, ?string $excerpt): array
    {
        $now = now();
        $existing = null;
        if (isset($data['id'])) {
            $existing = DB::table($table)->where('id', $data['id'])->lockForUpdate()->first();
            abort_unless($existing !== null, 404);
            abort_unless((int) $existing->version === (int) $data['version'], 409, 'This '.$kind.' changed. Reload before saving.');
        }
        $id = isset($data['id']) ? (string) $data['id'] : (string) Str::uuid();
        $version = $existing === null ? 1 : ((int) $existing->version) + 1;
        $payload = [
            'title' => $data['title'],
            'slug' => $data['slug'],
            'path' => $path,
        ];
        if ($excerpt !== null) {
            $payload['excerpt'] = $excerpt;
        }
        ContentRevisions::record($kind, $id, $version, $payload, $actor->id);
        $publishedRevision = $existing === null ? null : ($existing->published_revision !== null ? (int) $existing->published_revision : null);
        $publishedAt = $existing === null ? null : $existing->published_at;
        $status = $publishedRevision !== null ? 'published' : 'draft';
        if ($existing === null && $data['status'] === 'published') {
            $publishedRevision = $version;
            $publishedAt = $now;
            $status = 'published';
        }
        $row = [
            'title' => $data['title'],
            'slug' => $data['slug'],
            'path' => $path,
            'status' => $status,
            'version' => $version,
            'draft_revision' => $version,
            'published_revision' => $publishedRevision,
            'updated_by' => $actor->id,
            'published_at' => $publishedAt,
            'updated_at' => $now,
        ];
        if ($excerpt !== null) {
            $row['excerpt'] = $excerpt;
        }
        if ($existing === null) {
            DB::table($table)->insert($row + ['id' => $id, 'created_at' => $now]);
        } else {
            DB::table($table)->where('id', $id)->update($row);
        }
        $action = $status === 'published' && $existing === null
            ? 'content.'.$kind.'.published'
            : 'content.'.$kind.'.saved';
        app(RecordEvent::class)->record('platform.audit.recorded', 'content:'.$kind.':'.$id.':'.$version, [
            'action' => $action,
            'subject_type' => $kind,
            'subject_id' => $id,
            'actor_type' => 'staff',
            'actor_id' => $actor->id,
            'metadata' => ['path' => $path, 'version' => $version, 'published_revision' => $publishedRevision],
        ]);

        return ['id' => $id, 'path' => $path, 'version' => $version, 'status' => $status];
    }
}
