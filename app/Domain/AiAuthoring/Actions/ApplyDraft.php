<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Actions;

use App\Domain\AiAuthoring\Exceptions\AiAuthoringRejected;
use App\Domain\AiAuthoring\Exceptions\AiDraftConflict;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\ThemesContent\Actions\RenderSections;
use App\Domain\ThemesContent\Exceptions\SectionValidationFailed;
use App\Domain\ThemesContent\Support\ContentRevisions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;

/**
 * Applies a reviewed AI candidate to a content draft at the matching
 * base revision (AI-05). Publication is a separate permission and action.
 */
final class ApplyDraft
{
    public function __construct(
        private readonly ValidateCandidate $validate,
        private readonly RenderSections $render,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{draft_id: string, page_id: string, version: int, revision_id: string, published_revision: int}
     */
    public function apply(StaffAccount $actor, string $draftId, array $input): array
    {
        $this->assertDraftId($draftId);
        $data = Validator::make($input, [
            'expected_revision_id' => ['required', 'uuid'],
            'document' => ['required', 'array'],
        ])->validate();
        $expected = strtolower((string) $data['expected_revision_id']);
        $document = $data['document'];
        if (! is_array($document)) {
            throw ValidationException::withMessages(['document' => 'Provide a page draft document.']);
        }

        return DB::transaction(function () use ($actor, $draftId, $expected, $document): array {
            $draft = DB::table('ai_drafts')->where('id', $draftId)->lockForUpdate()->first();
            if ($draft === null) {
                abort(404);
            }
            $run = DB::table('ai_runs')->where('id', $this->string($draft, 'run_id'))->lockForUpdate()->first();
            if ($run === null) {
                abort(404);
            }
            $this->assertAuthor($actor, true);
            if (! $actor->hasPermission('content.write')) {
                throw new AuthorizationException;
            }
            $status = $this->string($draft, 'status');
            $base = DB::table('content_revisions')->where('id', $this->string($run, 'base_revision_id'))->lockForUpdate()->first();
            if ($base === null || $this->string($base, 'subject_type') !== 'page') {
                throw new AiAuthoringRejected;
            }
            $pageId = $this->string($base, 'subject_id');
            $page = DB::table('pages')->where('id', $pageId)->lockForUpdate()->first();
            if ($page === null) {
                abort(404);
            }
            $current = DB::table('content_revisions')
                ->where('subject_type', 'page')
                ->where('subject_id', $pageId)
                ->where('revision', (int) $this->string($page, 'draft_revision'))
                ->lockForUpdate()
                ->first();
            if ($current === null) {
                abort(404);
            }
            if ($status === 'applied') {
                return $this->outcome($this->string($draft, 'id'), $page, $this->string($current, 'id'));
            }
            if ($status !== 'candidate') {
                throw new AiAuthoringRejected;
            }
            $currentId = $this->string($current, 'id');
            $baseId = $this->string($run, 'base_revision_id');
            if ($expected !== $baseId || $currentId !== $baseId) {
                throw new AiDraftConflict($currentId);
            }
            $normalized = $this->validatedDocument($run, $document);
            $title = $normalized['title'] ?? null;
            $templateKey = $normalized['templateKey'] ?? null;
            $sections = $normalized['sections'] ?? null;
            if (! is_string($title) || ! is_string($templateKey) || ! is_array($sections) || ! array_is_list($sections)) {
                throw ValidationException::withMessages(['document' => 'The draft is not valid page content.']);
            }
            $version = (int) $this->string($page, 'version') + 1;
            $payload = [
                'title' => $title,
                'slug' => $this->string($page, 'slug'),
                'path' => $this->string($page, 'path'),
                'templateKey' => $templateKey,
                'sections' => $sections,
            ];
            ContentRevisions::record(ContentRevisions::PAGE, $pageId, $version, $payload, $actor->id);
            $revisionId = (string) DB::table('content_revisions')
                ->where('subject_type', 'page')
                ->where('subject_id', $pageId)
                ->where('revision', $version)
                ->value('id');
            $now = now();
            DB::table('pages')->where('id', $pageId)->update([
                'title' => $title,
                'version' => $version,
                'draft_revision' => $version,
                'updated_by' => $actor->id,
                'updated_at' => $now,
            ]);
            DB::table('ai_drafts')->where('id', $draftId)->update([
                'payload' => json_encode($normalized, JSON_THROW_ON_ERROR),
                'status' => 'applied',
                'updated_at' => $now,
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:ai-draft:'.$draftId.':'.$version, [
                'action' => 'ai.draft.applied',
                'subject_type' => 'page',
                'subject_id' => $pageId,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['draft_id' => $draftId, 'run_id' => $this->string($run, 'id'), 'version' => $version],
            ]);
            $fresh = DB::table('pages')->where('id', $pageId)->first();
            if ($fresh === null) {
                abort(404);
            }

            return $this->outcome($draftId, $fresh, $revisionId);
        });
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function preview(StaffAccount $actor, string $runId, array $document): string
    {
        if (! Str::isUuid($runId) || $runId !== strtolower($runId)) {
            abort(404);
        }
        $this->assertAuthor($actor, false);
        $run = DB::table('ai_runs')->where('id', $runId)->first();
        if ($run === null) {
            abort(404);
        }
        $normalized = $this->validatedDocument($run, $document);
        $sections = $normalized['sections'] ?? null;
        if (! is_array($sections) || ! array_is_list($sections)) {
            throw ValidationException::withMessages(['document' => 'The draft could not be previewed.']);
        }

        return $this->render->render('page', 'content', $sections)->render();
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function validatedDocument(object $run, array $document): array
    {
        $ids = $this->productIds($run);
        try {
            return $this->validate->document($document, $ids);
        } catch (InvalidArgumentException|JsonException|SectionValidationFailed|ValidationException) {
            throw ValidationException::withMessages(['document' => 'The draft is not valid page content.']);
        }
    }

    /** @return list<string> */
    private function productIds(object $run): array
    {
        $raw = get_object_vars($run)['product_ids'] ?? null;
        $ids = is_string($raw) ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR) : $raw;
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new InvalidArgumentException('Invalid stored product context.');
        }
        $products = [];
        foreach ($ids as $id) {
            if (is_string($id)) {
                $products[] = $id;
            }
        }

        return $products;
    }

    /** @return array{draft_id: string, page_id: string, version: int, revision_id: string, published_revision: int} */
    private function outcome(string $draftId, object $page, string $revisionId): array
    {
        $published = $this->string($page, 'published_revision');

        return [
            'draft_id' => $draftId,
            'page_id' => $this->string($page, 'id'),
            'version' => (int) $this->string($page, 'version'),
            'revision_id' => $revisionId,
            'published_revision' => $published === '' ? 0 : (int) $published,
        ];
    }

    private function assertAuthor(StaffAccount $actor, bool $lock): void
    {
        $authors = DB::table('ai_authors')->where('staff_id', $actor->id);
        if ($lock) {
            $authors->lockForUpdate();
        }
        if (! $actor->hasPermission('ai.use') || $authors->first() === null) {
            throw new AuthorizationException;
        }
    }

    private function assertDraftId(string $draftId): void
    {
        if (! Str::isUuid($draftId) || $draftId !== strtolower($draftId)) {
            abort(404);
        }
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
