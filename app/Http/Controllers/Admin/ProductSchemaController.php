<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\ApplySchemaMigration;
use App\Domain\Catalog\Actions\PreviewSchemaMigration;
use App\Domain\Catalog\Actions\ReadCatalog;
use App\Domain\Catalog\Actions\SaveProductSchema;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ProductSchemaController
{
    public function index(Request $request, ReadCatalog $catalog): Response
    {
        Gate::authorize('catalog.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return Inertia::render('ProductSchemaIndex', [
            'schemas' => $catalog->staffSchemas(),
            'canWrite' => $staff->hasPermission('catalog.write'),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('catalog.write');

        return Inertia::render('ProductSchema', [
            'schema' => null,
            'saved' => session()->get('schema_saved', false),
            'migrated' => false,
            'migration' => null,
        ]);
    }

    public function edit(string $schemaId, ReadCatalog $catalog): Response
    {
        Gate::authorize('catalog.write');

        return Inertia::render('ProductSchema', [
            'schema' => $catalog->staffSchema($schemaId),
            'saved' => session()->get('schema_saved', false),
            'migrated' => session()->get('schema_migrated', false),
            'migration' => session()->get('schema_migration'),
        ]);
    }

    public function store(Request $request, SaveProductSchema $save): RedirectResponse
    {
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return $this->persist($request, $save, $staff, $this->validated($request, false), '/admin/schemas/create');
    }

    public function update(Request $request, string $schemaId, SaveProductSchema $save): RedirectResponse
    {
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $this->validated($request, true);
        $input['id'] = $schemaId;

        return $this->persist($request, $save, $staff, $input, '/admin/schemas/'.$schemaId);
    }

    public function previewMigration(Request $request, string $schemaId, PreviewSchemaMigration $preview): RedirectResponse
    {
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $this->validated($request, true);
        $input['id'] = $schemaId;
        try {
            $report = $preview->preview($staff, $input);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 409 && ! $request->expectsJson()) {
                throw ValidationException::withMessages(['schema' => $exception->getMessage()])->redirectTo('/admin/schemas/'.$schemaId);
            }
            throw $exception;
        }

        return redirect('/admin/schemas/'.$schemaId)->with('schema_migration', $report);
    }

    public function applyMigration(Request $request, string $schemaId, ApplySchemaMigration $apply): RedirectResponse
    {
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $this->validated($request, true);
        $input['id'] = $schemaId;
        try {
            $apply->apply($staff, $input);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 409 && ! $request->expectsJson()) {
                throw ValidationException::withMessages(['schema' => $exception->getMessage()])->redirectTo('/admin/schemas/'.$schemaId);
            }
            throw $exception;
        }

        return redirect('/admin/schemas/'.$schemaId)->with('schema_migrated', true);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $update): array
    {
        $keys = 'id,key,label,comparison_group,default_template_key,attributes,version';
        $data = $request->validate([
            'schema' => ['required', 'array:'.$keys],
            'schema.key' => ['required', 'string', 'max:40'],
            'schema.label' => ['required', 'string', 'max:80'],
            'schema.comparison_group' => ['required', 'string', 'max:40'],
            'schema.default_template_key' => ['required', 'string', 'max:80'],
            'schema.attributes' => ['required', 'array', 'max:30'],
            'schema.version' => [$update ? 'required' : 'sometimes', 'nullable', 'integer'],
        ])['schema'];
        if (! $update) {
            unset($data['version']);
        }

        return $data;
    }

    /** @param array<string, mixed> $input */
    private function persist(Request $request, SaveProductSchema $save, StaffAccount $staff, array $input, string $fallback): RedirectResponse
    {
        try {
            $saved = $save->save($staff, $input);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 409 && ! $request->expectsJson()) {
                throw ValidationException::withMessages(['schema' => $exception->getMessage()])->redirectTo($fallback);
            }
            throw $exception;
        }

        return redirect('/admin/schemas/'.$saved['id'])->with('schema_saved', true);
    }
}
