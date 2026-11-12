<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\AiAuthoring\Actions\ApplyDraft;
use App\Domain\AiAuthoring\Actions\DispatchAuthoringRun;
use App\Domain\AiAuthoring\Actions\ReadAuthoringDraft;
use App\Domain\AiAuthoring\Exceptions\AiAuthoringDisabled;
use App\Domain\AiAuthoring\Exceptions\AiAuthoringRejected;
use App\Domain\AiAuthoring\Exceptions\AiBudgetExhausted;
use App\Domain\AiAuthoring\Exceptions\AiDraftConflict;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\ThemesContent\Exceptions\SectionValidationFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Author review of an AI page candidate (AI-05). Generate queues a
 * budgeted run. Apply writes a content draft at the matching base
 * revision. Publishing stays on the content screens and `content.publish`.
 */
final class AiStudioController
{
    public function index(Request $request, ReadAuthoringDraft $read): Response
    {
        $staff = $this->actor($request);

        return Inertia::render('AiStudio', $read->catalog($staff) + [
            'runs' => $read->listFor($staff),
            'canPublish' => $staff->hasPermission('content.publish'),
            'canApply' => false,
            'canCancel' => false,
            'stale' => false,
            'runId' => null,
            'draftId' => null,
            'status' => null,
            'statusMessage' => '',
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
        $staff = $this->actor($request);

        return Inertia::render('AiStudio', $read->forRun($staff, $runId) + $read->catalog($staff) + ['runs' => []]);
    }

    public function generate(Request $request, DispatchAuthoringRun $runs): RedirectResponse
    {
        $staff = $this->actor($request);
        $input = $request->validate([
            'prompt' => ['required', 'string', 'min:1', 'max:4000'],
            'locale' => ['sometimes', 'string', 'max:16'],
            'product_ids' => ['present', 'array', 'max:20'],
            'product_ids.*' => ['uuid'],
            'page_id' => ['required', 'uuid'],
            'max_output_tokens' => ['sometimes', 'integer', 'min:1', 'max:8192'],
            'intent_id' => ['required', 'uuid'],
        ]);
        $page = DB::table('pages')->where('id', strtolower((string) $input['page_id']))->first();
        abort_unless($page !== null, 404);
        $revisionId = (string) DB::table('content_revisions')
            ->where('subject_type', 'page')
            ->where('subject_id', $page->id)
            ->where('revision', (int) $page->draft_revision)
            ->value('id');
        abort_unless($revisionId !== '', 404);
        try {
            $runId = $runs->queue($staff, [
                'prompt' => $input['prompt'],
                'locale' => $input['locale'] ?? 'en-US',
                'product_ids' => $input['product_ids'],
                'base_revision_id' => $revisionId,
                'max_output_tokens' => $input['max_output_tokens'] ?? 1024,
                'intent_id' => $input['intent_id'],
            ]);
        } catch (AiAuthoringDisabled|AiBudgetExhausted|AiAuthoringRejected $exception) {
            throw ValidationException::withMessages(['prompt' => $exception->getMessage()]);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 409) {
                throw ValidationException::withMessages(['intent_id' => $exception->getMessage()]);
            }
            throw $exception;
        }

        return redirect('/admin/ai/studio/'.$runId);
    }

    public function cancel(Request $request, string $runId, DispatchAuthoringRun $runs): RedirectResponse
    {
        $staff = $this->actor($request);
        $runs->cancel($staff, $runId);

        return redirect('/admin/ai/studio/'.$runId);
    }

    public function preview(Request $request, string $runId, ApplyDraft $apply): JsonResponse
    {
        $staff = $this->actor($request);
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
        Gate::authorize('content.write');
        $staff = $this->actor($request);
        $review = $read->forRun($staff, $runId);
        abort_unless($review['draftId'] !== '', 404);
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

    private function actor(Request $request): StaffAccount
    {
        Gate::authorize('ai.use');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return $staff;
    }
}
