<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Support\ReservedCatalogSlugs;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveCategory
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, path: string, version: int, status: string}
     */
    public function save(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('catalog.write')) {
            throw new AuthorizationException;
        }
        $data = Validator::make(['category' => $input], [
            'category' => ['required', 'array:id,version,title,slug,description,status,parent_id'],
            'category.id' => ['nullable', 'uuid'],
            'category.version' => ['required_with:category.id', 'integer', 'min:1', 'max:4294967295'],
            'category.title' => ['required', 'string', 'max:200'],
            'category.slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(ReservedCatalogSlugs::all())],
            'category.description' => ['required', 'string', 'max:5000'],
            'category.status' => ['required', Rule::in(['draft', 'published'])],
            'category.parent_id' => ['nullable', 'uuid'],
        ])->validate()['category'];
        try {
            return DB::transaction(function () use ($actor, $data): array {
                $now = now();
                $existing = null;
                if (isset($data['id'])) {
                    $existing = DB::table('categories')->where('id', $data['id'])->lockForUpdate()->first();
                    abort_unless($existing !== null, 404);
                    abort_unless((int) $existing->version === (int) $data['version'], 409, 'This category changed. Reload before saving.');
                    abort_if((int) $existing->version >= 4294967295, 409);
                }
                $parent = null;
                if (isset($data['parent_id'])) {
                    $parent = DB::table('categories')->where('id', $data['parent_id'])->lockForUpdate()->first();
                    if ($parent === null) {
                        throw ValidationException::withMessages(['category.parent_id' => 'Choose a category that exists.']);
                    }
                    if ($existing !== null && ($data['parent_id'] === $data['id'] || str_starts_with((string) $parent->path, (string) $existing->path.'/') || $parent->path === $existing->path)) {
                        throw ValidationException::withMessages(['category.parent_id' => 'A category cannot be nested under itself.']);
                    }
                }
                $path = $parent === null ? $data['slug'] : $parent->path.'/'.$data['slug'];
                $depth = $parent === null ? 1 : ((int) $parent->depth) + 1;
                if ($depth > 5 || substr_count($path, '/') > 4) {
                    throw ValidationException::withMessages(['category.parent_id' => 'Categories can be nested five levels deep.']);
                }
                $id = isset($data['id']) ? (string) $data['id'] : (string) Str::uuid();
                $version = $existing === null ? 1 : ((int) $existing->version) + 1;
                $publishedAt = $data['status'] === 'published'
                    ? ($existing !== null && $existing->status === 'published' ? $existing->published_at : $now)
                    : null;
                $row = [
                    'parent_id' => $data['parent_id'] ?? null,
                    'title' => $data['title'],
                    'slug' => $data['slug'],
                    'path' => $path,
                    'description' => $data['description'],
                    'status' => $data['status'],
                    'version' => $version,
                    'depth' => $depth,
                    'published_at' => $publishedAt,
                    'updated_at' => $now,
                ];
                if ($existing === null) {
                    DB::table('categories')->insert($row + ['id' => $id, 'created_at' => $now]);
                } else {
                    $oldPath = (string) $existing->path;
                    DB::table('categories')->where('id', $id)->update($row);
                    if ($oldPath !== $path) {
                        $this->rewriteDescendants($oldPath, $path, $now);
                        $this->recordRedirect($oldPath, $path, $now);
                    }
                }
                $action = $data['status'] === 'published' ? 'catalog.category.published' : 'catalog.category.saved';
                app(RecordEvent::class)->record('platform.audit.recorded', 'catalog:category:'.$id.':'.$version, [
                    'action' => $action, 'subject_type' => 'category', 'subject_id' => $id,
                    'actor_type' => 'staff', 'actor_id' => $actor->id, 'metadata' => ['version' => $version],
                ]);

                return ['id' => $id, 'path' => $path, 'version' => $version, 'status' => $data['status']];
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['category.slug' => 'This category path is already used.']);
        }
    }

    private function rewriteDescendants(string $oldPath, string $newPath, mixed $now): void
    {
        $descendants = DB::table('categories')->where('path', 'like', $oldPath.'/%')->orderBy('path')->lockForUpdate()->get();
        foreach ($descendants as $descendant) {
            $suffix = substr((string) $descendant->path, strlen($oldPath));
            $childPath = $newPath.$suffix;
            $childDepth = substr_count($childPath, '/') + 1;
            if ($childDepth > 5) {
                throw ValidationException::withMessages(['category.parent_id' => 'Categories can be nested five levels deep.']);
            }
            DB::table('categories')->where('id', $descendant->id)->update([
                'path' => $childPath,
                'depth' => $childDepth,
                'updated_at' => $now,
            ]);
            $this->recordRedirect((string) $descendant->path, $childPath, $now);
        }
    }

    private function recordRedirect(string $fromPath, string $toPath, mixed $now): void
    {
        if ($fromPath === $toPath) {
            return;
        }
        DB::table('category_redirects')->where('from_path', $toPath)->delete();
        DB::table('category_redirects')->where('to_path', $fromPath)->update(['to_path' => $toPath, 'updated_at' => $now]);
        DB::table('category_redirects')->whereColumn('from_path', 'to_path')->delete();
        $existing = DB::table('category_redirects')->where('from_path', $fromPath)->lockForUpdate()->first();
        if ($existing === null) {
            DB::table('category_redirects')->insert([
                'id' => (string) Str::uuid(),
                'from_path' => $fromPath,
                'to_path' => $toPath,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }
        DB::table('category_redirects')->where('id', $existing->id)->update(['to_path' => $toPath, 'updated_at' => $now]);
    }
}
