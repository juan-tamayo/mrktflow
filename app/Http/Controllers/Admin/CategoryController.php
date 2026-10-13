<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\ReadCatalog;
use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Exceptions\CatalogCategoryNotFound;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CategoryController
{
    public function index(Request $request, ReadCatalog $catalog): Response
    {
        Gate::authorize('catalog.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return Inertia::render('CategoryIndex', [
            'categories' => $catalog->staffCategories(),
            'canWrite' => $staff->hasPermission('catalog.write'),
        ]);
    }

    public function create(ReadCatalog $catalog): Response
    {
        Gate::authorize('catalog.write');

        return Inertia::render('CategoryEdit', [
            'category' => null,
            'parents' => $catalog->staffCategories(),
            'saved' => session()->get('category_saved', false),
        ]);
    }

    public function edit(string $categoryId, ReadCatalog $catalog): Response
    {
        Gate::authorize('catalog.write');
        try {
            $category = $catalog->staffCategory($categoryId);
        } catch (CatalogCategoryNotFound) {
            abort(404);
        }
        $parents = [];
        foreach ($catalog->staffCategories() as $candidate) {
            if ($candidate['id'] === $category['id'] || str_starts_with($candidate['path'], $category['path'].'/')) {
                continue;
            }
            $parents[] = $candidate;
        }

        return Inertia::render('CategoryEdit', [
            'category' => $category,
            'parents' => $parents,
            'saved' => session()->get('category_saved', false),
        ]);
    }

    public function store(Request $request, SaveCategory $save): RedirectResponse
    {
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return $this->persist($request, $save, $staff, $this->validated($request, false), '/admin/categories/create');
    }

    public function update(Request $request, string $categoryId, SaveCategory $save): RedirectResponse
    {
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $this->validated($request, true);
        $input['id'] = $categoryId;

        return $this->persist($request, $save, $staff, $input, '/admin/categories/'.$categoryId);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $update): array
    {
        $keys = 'title,slug,description,status,parent_id,version';
        $rules = [
            'category' => ['required', 'array:'.$keys],
            'category.title' => ['required', 'string', 'max:200'],
            'category.slug' => ['required', 'string', 'max:80'],
            'category.description' => ['required', 'string', 'max:5000'],
            'category.status' => ['required', 'string'],
            'category.parent_id' => ['nullable', 'uuid'],
            'category.version' => [$update ? 'required' : 'sometimes', 'nullable', 'integer'],
        ];
        $data = $request->validate($rules)['category'];
        if (($data['parent_id'] ?? null) === '') {
            $data['parent_id'] = null;
        }

        return $data;
    }

    /** @param array<string, mixed> $input */
    private function persist(Request $request, SaveCategory $save, StaffAccount $staff, array $input, string $fallback): RedirectResponse
    {
        try {
            $saved = $save->save($staff, $input);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 409 && ! $request->expectsJson()) {
                throw ValidationException::withMessages(['category' => $exception->getMessage()])->redirectTo($fallback);
            }
            throw $exception;
        }

        return redirect('/admin/categories/'.$saved['id'])->with('category_saved', true);
    }
}
