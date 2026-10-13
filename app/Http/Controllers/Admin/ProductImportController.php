<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\ApplyProductImport;
use App\Domain\Catalog\Actions\PreviewProductImport;
use App\Domain\Catalog\Support\ProductImportCsv;
use App\Domain\Identity\Models\StaffAccount;
use App\Http\Requests\PreviewProductImportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ProductImportController
{
    public function create(): Response
    {
        Gate::authorize('catalog.write');

        return Inertia::render('ProductImport', [
            'sampleColumns' => ProductImportCsv::sampleColumns(),
            'preview' => null,
            'applied' => null,
        ]);
    }

    public function store(PreviewProductImportRequest $request, PreviewProductImport $preview): RedirectResponse
    {
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $request->validated();
        if ($request->file('file') !== null) {
            $input['file'] = $request->file('file');
        }
        try {
            $report = $preview->preview($staff, $input);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 404) {
                throw $exception;
            }
            if (! $request->expectsJson()) {
                throw ValidationException::withMessages(['file' => $exception->getMessage()])->redirectTo('/admin/products/import');
            }
            throw $exception;
        }

        return redirect('/admin/products/import/'.$report['import_id']);
    }

    public function show(string $importId, Request $request, PreviewProductImport $preview): Response
    {
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        abort_unless(DB::table('product_import_files')->where('id', $importId)->exists(), 404);
        $report = $preview->preview($staff, ['import_id' => $importId]);

        return Inertia::render('ProductImport', [
            'sampleColumns' => ProductImportCsv::sampleColumns(),
            'preview' => $report,
            'applied' => $this->appliedResult($importId),
        ]);
    }

    public function apply(string $importId, Request $request, ApplyProductImport $apply): RedirectResponse
    {
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        abort_unless(DB::table('product_import_files')->where('id', $importId)->exists(), 404);
        $apply->apply($staff, ['import_id' => $importId]);

        return redirect('/admin/products/import/'.$importId);
    }

    public function errors(string $importId, Request $request, PreviewProductImport $preview): StreamedResponse
    {
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        abort_unless(DB::table('product_import_files')->where('id', $importId)->exists(), 404);
        $report = $preview->preview($staff, ['import_id' => $importId]);
        $csv = $preview->errorCsv($report);

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, 'catalog-import-errors.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function appliedResult(string $importId): ?array
    {
        $raw = DB::table('product_import_files')->where('id', $importId)->value('apply_result');
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
