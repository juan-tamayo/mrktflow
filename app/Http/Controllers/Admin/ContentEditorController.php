<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\ThemesContent\Actions\ReadSectionDraft;
use App\Domain\ThemesContent\Actions\RenderSections;
use App\Domain\ThemesContent\Actions\SaveSectionDraft;
use App\Domain\ThemesContent\Data\SectionDefinition;
use App\Domain\ThemesContent\Exceptions\SectionDraftConflict;
use App\Domain\ThemesContent\Exceptions\SectionValidationFailed;
use App\Domain\ThemesContent\Support\SectionDraftTargets;
use App\Domain\ThemesContent\Support\SectionRegistry;
use App\Domain\ThemesContent\Support\SectionValidationErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The section editor (THEME-02, WEB-06, T064). Reading, loading a draft
 * and previewing sit under `content.read`; saving sits under
 * `content.write` — the server enforces both, the page only reflects
 * them. Save and preview speak JSON so a validation failure can return
 * T063's errors grouped per section and the editor keeps its working
 * copy; preview renders through RenderSections and persists nothing.
 */
final class ContentEditorController
{
    public function show(Request $request, SectionRegistry $registry, ReadSectionDraft $read): Response
    {
        Gate::authorize('content.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $draft = $read->read('home', 'content');

        return Inertia::render('ContentEditor', [
            'definitions' => array_map(
                static fn (SectionDefinition $definition): array => [
                    'type' => $definition->type,
                    'version' => $definition->version,
                    'label' => $definition->label,
                    'settings' => $definition->settings,
                    'binding' => $definition->binding,
                    'maxPerPage' => $definition->maxPerPage,
                ],
                array_values($registry->definitions()),
            ),
            'targets' => SectionDraftTargets::all(),
            'initial' => [
                'page' => 'home',
                'slot' => 'content',
                'version' => $draft['version'],
                'sections' => $draft['sections'],
            ],
            'canWrite' => $staff->hasPermission('content.write'),
        ]);
    }

    public function draft(Request $request, ReadSectionDraft $read): JsonResponse
    {
        Gate::authorize('content.read');
        $data = $request->validate([
            'page' => ['required', 'string', 'max:64'],
            'slot' => ['required', 'string', 'max:64'],
        ]);
        if (! SectionDraftTargets::isEditable($data['page'], $data['slot'])) {
            return $this->invalidTarget($data['page'], $data['slot']);
        }
        $draft = $read->read($data['page'], $data['slot']);

        return response()->json([
            'page' => $data['page'],
            'slot' => $data['slot'],
            'version' => $draft['version'],
            'sections' => $draft['sections'],
            'updatedAt' => $draft['updatedAt'],
        ]);
    }

    public function save(Request $request, SaveSectionDraft $save): JsonResponse
    {
        Gate::authorize('content.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $data = $request->validate([
            'page' => ['required', 'string', 'max:64'],
            'slot' => ['required', 'string', 'max:64'],
            'version' => ['nullable', 'integer', 'min:0'],
            'sections' => ['required', 'array'],
        ]);
        if (! SectionDraftTargets::isEditable($data['page'], $data['slot'])) {
            return $this->invalidTarget($data['page'], $data['slot']);
        }
        try {
            $result = $save->save($staff, $data['page'], $data['slot'], $data['version'] ?? null, $data['sections']);
        } catch (SectionValidationFailed $failure) {
            return $this->validationFailed($failure);
        } catch (SectionDraftConflict $conflict) {
            return response()->json([
                'message' => $conflict->getMessage(),
                'currentVersion' => $conflict->currentVersion,
                'sections' => $conflict->sections,
            ], 409);
        }

        return response()->json([
            'page' => $data['page'],
            'slot' => $data['slot'],
            'version' => $result['version'],
            'sections' => $result['sections'],
        ]);
    }

    public function preview(Request $request, RenderSections $render): JsonResponse
    {
        Gate::authorize('content.read');
        $data = $request->validate([
            'page' => ['required', 'string', 'max:64'],
            'slot' => ['required', 'string', 'max:64'],
            'sections' => ['required', 'array'],
        ]);
        if (! SectionDraftTargets::isEditable($data['page'], $data['slot'])) {
            return $this->invalidTarget($data['page'], $data['slot']);
        }
        try {
            $html = $render->render($data['page'], $data['slot'], $data['sections'])->render();
        } catch (SectionValidationFailed $failure) {
            return $this->validationFailed($failure);
        }

        return response()->json(['html' => $html]);
    }

    private function invalidTarget(string $page, string $slot): JsonResponse
    {
        $message = "Page [{$page}] has no editable section slot [{$slot}].";

        return response()->json([
            'message' => $message,
            'errors' => ['document' => [$message], 'sections' => (object) []],
        ], 422);
    }

    private function validationFailed(SectionValidationFailed $failure): JsonResponse
    {
        $errors = SectionValidationErrors::group($failure);

        return response()->json([
            'message' => 'The sections could not be saved. Fix the problems listed and try again.',
            'errors' => ['document' => $errors['document'], 'sections' => (object) $errors['sections']],
        ], 422);
    }
}
