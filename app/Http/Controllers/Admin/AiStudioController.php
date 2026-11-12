<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\AiAuthoring\Actions\ApplyDraft;
use App\Domain\AiAuthoring\Actions\ReadAuthoringDraft;
use App\Domain\AiAuthoring\Exceptions\AiDraftConflict;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\ThemesContent\Exceptions\SectionValidationFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Author review of an AI page candidate (AI-05). Apply writes a content
 * draft at the matching base revision. Publishing stays on the content
 * screens and `content.publish`.
 */
final class AiStudioController
{
    public function index(Request $request, ReadAuthoringDraft $read): Response
    {
        Gate::authorize('ai.use');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return Inertia::render('AiStudio', [
            'runs' => $read->listFor($staff),
            'canPublish' => $staff->hasPermission('content.publish'),
            'canApply' => false,
            'stale' => false,
            'runId' => null,
            'draftId' => null,
            'status' => null,
            'expectedRevisionId' => null,
            'currentRevisionId' => null,
            'pageId' => null,
            'pageVersion' => 0,
            'publishedRevision' => 0,
            'prompt' => '',
            'model' => '',
            'candidate' => null,
            'base' => null,
            'usage' => ['input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null, 'known' => false],
            'warnings' => [
                'Price, stock and campaign facts stay merchant-authored; review product claims before publishing.',
                'Applying writes a content draft only. Publishing stays a separate permission and action.',
            ],
        ]);
    }

    public function show(Request $request, string $runId, ReadAuthoringDraft $read): Response
    {
        Gate::authorize('ai.use');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return Inertia::render('AiStudio', $read->forRun($staff, $runId) + ['runs' => []]);
    }

    public function preview(Request $request, string $runId, ApplyDraft $apply): JsonResponse
    {
        Gate::authorize('ai.use');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $document = $request->validate([
            'document' => ['required', 'array'],
        ])['document'];
        abort_unless(is_array($document), 422);
        try {
            $html = $apply->preview($staff, $runId, $document);
        } catch (SectionValidationFailed $failure) {
            throw ValidationException::withMessages(['document' => $failure->getMessage()]);
        }

        return response()->json(['html' => $html]);
    }

    public function apply(Request $request, string $runId, ApplyDraft $apply, ReadAuthoringDraft $read): JsonResponse
    {
        Gate::authorize('ai.use');
        Gate::authorize('content.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $review = $read->forRun($staff, $runId);
        $input = $request->validate([
            'expected_revision_id' => ['required', 'uuid'],
            'document' => ['required', 'array'],
        ]);
        try {
            $result = $apply->apply($staff, $review['draftId'], $input);
        } catch (AiDraftConflict $conflict) {
            return response()->json([
                'message' => $conflict->getMessage(),
                'currentRevisionId' => $conflict->currentRevisionId,
            ], 409);
        } catch (SectionValidationFailed $failure) {
            throw ValidationException::withMessages(['document' => $failure->getMessage()]);
        }

        return response()->json($result);
    }
}
