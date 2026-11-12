<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\ThemesContent\Actions\ApplyImport;
use App\Domain\ThemesContent\Actions\ValidateImport;
use App\Http\Requests\ValidateTemplateImportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff data-only template import (THEME-04, T069).
 *
 * Reading the page sits under `content.read`; staging and apply sit under
 * `content.write`. Apply never publishes.
 */
final class TemplateImportController
{
    public function create(Request $request): Response
    {
        Gate::authorize('content.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return Inertia::render('TemplateImport', [
            'preview' => null,
            'applied' => null,
            'canWrite' => $staff->hasPermission('content.write'),
        ]);
    }

    public function store(ValidateTemplateImportRequest $request, ValidateImport $validate): RedirectResponse
    {
        Gate::authorize('content.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $request->validated();
        if ($request->file('file') !== null) {
            $input['file'] = $request->file('file');
        }
        $report = $validate->validate($staff, $input);

        return redirect('/admin/content/import/'.$report['import_id']);
    }

    public function show(string $importId, Request $request, ValidateImport $validate): Response
    {
        Gate::authorize('content.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        abort_unless(DB::table('content_import_files')->where('id', $importId)->exists(), 404);
        $report = $validate->validate($staff, ['import_id' => $importId]);

        return Inertia::render('TemplateImport', [
            'preview' => $report,
            'applied' => $this->appliedResult($importId),
            'canWrite' => $staff->hasPermission('content.write'),
        ]);
    }

    public function apply(string $importId, Request $request, ApplyImport $apply): RedirectResponse
    {
        Gate::authorize('content.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        abort_unless(DB::table('content_import_files')->where('id', $importId)->exists(), 404);
        $input = $request->validate([
            'references' => ['sometimes', 'array'],
            'references.products' => ['sometimes', 'array'],
            'references.products.*' => ['uuid'],
            'references.categories' => ['sometimes', 'array'],
            'references.categories.*' => ['uuid'],
        ]);
        $apply->apply($staff, ['import_id' => $importId] + $input);

        return redirect('/admin/content/import/'.$importId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function appliedResult(string $importId): ?array
    {
        $raw = DB::table('content_import_files')->where('id', $importId)->value('apply_result');
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
