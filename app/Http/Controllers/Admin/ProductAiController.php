<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\AiAuthoring\Actions\DraftProductContent;
use App\Domain\AiAuthoring\Actions\SuggestProductSchema;
use App\Domain\AiAuthoring\Data\AiAuthoringRequest;
use App\Domain\AiAuthoring\Exceptions\AiDraftConflict;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Product copy and schema suggestions (AI-02, CAT-04). Apply never writes
 * price or stock and refuses silent semantic-key meaning changes.
 */
final class ProductAiController
{
    public function copy(Request $request, string $productId, DraftProductContent $drafts): RedirectResponse
    {
        $staff = $this->actor($request);
        $input = $request->validate([
            'prompt' => ['required', 'string', 'min:1', 'max:4000'],
            'locale' => ['sometimes', 'string', 'max:16'],
            'max_output_tokens' => ['sometimes', 'integer', 'min:1', 'max:8192'],
        ]);
        $drafts->queue($staff, [
            'product_id' => $productId,
            'prompt' => $input['prompt'],
            'locale' => $input['locale'] ?? 'en-US',
            'max_output_tokens' => $input['max_output_tokens'] ?? 1024,
        ]);

        return redirect('/admin/products/'.$productId);
    }

    public function schema(Request $request, string $productId, SuggestProductSchema $schemas): RedirectResponse
    {
        $staff = $this->actor($request);
        $input = $request->validate([
            'prompt' => ['required', 'string', 'min:1', 'max:4000'],
            'locale' => ['sometimes', 'string', 'max:16'],
            'max_output_tokens' => ['sometimes', 'integer', 'min:1', 'max:8192'],
        ]);
        $schemas->queue($staff, [
            'product_id' => $productId,
            'prompt' => $input['prompt'],
            'locale' => $input['locale'] ?? 'en-US',
            'max_output_tokens' => $input['max_output_tokens'] ?? 1024,
        ]);

        return redirect('/admin/products/'.$productId);
    }

    public function apply(
        Request $request,
        string $productId,
        string $runId,
        DraftProductContent $copy,
        SuggestProductSchema $schemas,
    ): RedirectResponse {
        $staff = $this->actor($request);
        $run = DB::table('ai_runs')->where('id', $runId)->where('subject_id', $productId)->first();
        abort_unless($run !== null, 404);
        $draftId = (string) DB::table('ai_drafts')->where('run_id', $runId)->value('id');
        abort_unless($draftId !== '', 404);
        $input = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'document' => ['required', 'array'],
        ]);
        $operation = is_string($run->operation ?? null) ? (string) $run->operation : '';
        try {
            if ($operation === AiAuthoringRequest::PRODUCT_COPY) {
                $copy->apply($staff, $draftId, $input);
            } elseif ($operation === AiAuthoringRequest::PRODUCT_SCHEMA) {
                $schemas->apply($staff, $draftId, $input);
            } else {
                abort(404);
            }
        } catch (AiDraftConflict $conflict) {
            throw ValidationException::withMessages(['document' => $conflict->getMessage()])->redirectTo('/admin/products/'.$productId);
        }

        return redirect('/admin/products/'.$productId)->with('product_ai_applied', true);
    }

    private function actor(Request $request): StaffAccount
    {
        Gate::authorize('ai.use');
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return $staff;
    }
}
