<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Actions;

use App\Domain\AiAuthoring\Exceptions\AiAuthoringRejected;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * Assembles an AI candidate for review (AI-05): editable document, base
 * revision, usage, warnings and whether apply is still on the matching
 * revision. Publication is never included.
 */
final class ReadAuthoringDraft
{
    /**
     * @return list<array{runId: string, draftId: string, status: string, statusMessage: string, title: string, updatedAt: string}>
     */
    public function listFor(StaffAccount $actor): array
    {
        $this->assertAuthor($actor);
        $rows = DB::table('ai_runs')
            ->leftJoin('ai_drafts', 'ai_drafts.run_id', '=', 'ai_runs.id')
            ->where('ai_runs.staff_id', $actor->id)
            ->where('ai_runs.operation', 'draft_page')
            ->orderByDesc('ai_runs.updated_at')
            ->limit(50)
            ->get([
                'ai_runs.id as run_id',
                'ai_runs.status as run_status',
                'ai_runs.status_message',
                'ai_runs.prompt',
                'ai_runs.updated_at as run_updated_at',
                'ai_drafts.id as draft_id',
                'ai_drafts.status as draft_status',
                'ai_drafts.payload',
                'ai_drafts.updated_at as draft_updated_at',
            ]);
        $items = [];
        foreach ($rows as $row) {
            $draftId = $this->string($row, 'draft_id');
            $title = 'Page draft';
            if ($draftId !== '') {
                $payload = $this->json($row, 'payload');
                $title = is_string($payload['title'] ?? null) ? $payload['title'] : 'Untitled draft';
            } elseif ($this->string($row, 'prompt') !== '') {
                $title = $this->string($row, 'prompt');
            }
            $items[] = [
                'runId' => $this->string($row, 'run_id'),
                'draftId' => $draftId,
                'status' => $draftId === '' ? $this->string($row, 'run_status') : $this->string($row, 'draft_status'),
                'statusMessage' => $this->string($row, 'status_message'),
                'title' => $title,
                'updatedAt' => $draftId === '' ? $this->string($row, 'run_updated_at') : $this->string($row, 'draft_updated_at'),
            ];
        }

        return $items;
    }

    /**
     * @return array{pages: list<array{id: string, title: string}>, products: list<array{id: string, title: string}>}
     */
    public function catalog(StaffAccount $actor): array
    {
        $this->assertAuthor($actor);
        $pages = [];
        foreach (DB::table('pages')->orderBy('title')->orderBy('id')->get(['id', 'title']) as $page) {
            $pages[] = [
                'id' => $this->string($page, 'id'),
                'title' => $this->string($page, 'title'),
            ];
        }
        $products = [];
        foreach (DB::table('products')->where('status', 'published')->orderBy('title')->orderBy('id')->get(['id', 'title']) as $product) {
            $products[] = [
                'id' => $this->string($product, 'id'),
                'title' => $this->string($product, 'title'),
            ];
        }

        return ['pages' => $pages, 'products' => $products];
    }

    /**
     * @return array{
     *     runId: string,
     *     draftId: string,
     *     status: string,
     *     statusMessage: string,
     *     stale: bool,
     *     canApply: bool,
     *     canCancel: bool,
     *     canPublish: bool,
     *     expectedRevisionId: string,
     *     currentRevisionId: string,
     *     pageId: string,
     *     pageVersion: int,
     *     publishedRevision: int,
     *     prompt: string,
     *     model: string,
     *     candidate: array<string, mixed>|null,
     *     base: array<string, mixed>,
     *     usage: array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null, known: bool},
     *     warnings: list<string>
     * }
     */
    public function forRun(StaffAccount $actor, string $runId): array
    {
        $this->assertRunId($runId);
        $run = DB::table('ai_runs')->where('id', $runId)->first();
        if ($run === null || $this->string($run, 'operation') === 'draft_product_copy' || $this->string($run, 'operation') === 'suggest_product_schema') {
            abort(404);
        }
        $this->assertAuthor($actor);
        $draft = DB::table('ai_drafts')->where('run_id', $runId)->first();
        $baseId = $this->string($run, 'base_revision_id');
        $base = DB::table('content_revisions')->where('id', $baseId)->first();
        if ($base === null || $this->string($base, 'subject_type') !== 'page') {
            throw new AiAuthoringRejected;
        }
        $pageId = $this->string($base, 'subject_id');
        $page = DB::table('pages')->where('id', $pageId)->first();
        if ($page === null) {
            abort(404);
        }
        $current = DB::table('content_revisions')
            ->where('subject_type', 'page')
            ->where('subject_id', $pageId)
            ->where('revision', (int) $this->string($page, 'draft_revision'))
            ->first();
        if ($current === null) {
            abort(404);
        }
        $currentId = $this->string($current, 'id');
        $stale = $currentId !== $baseId;
        $runStatus = $this->string($run, 'status');
        $status = $draft === null ? $runStatus : $this->string($draft, 'status');
        $candidate = $draft === null ? null : $this->document($this->json($draft, 'payload'));
        $usage = DB::table('ai_usage_entries')->where('intent_id', $this->string($run, 'intent_id'))->first();

        return [
            'runId' => $runId,
            'draftId' => $draft === null ? '' : $this->string($draft, 'id'),
            'status' => $status,
            'statusMessage' => $this->string($run, 'status_message'),
            'stale' => $stale,
            'canApply' => $status === 'candidate' && $candidate !== null && ! $stale && $actor->hasPermission('content.write'),
            'canCancel' => in_array($runStatus, ['queued', 'running'], true) && $this->string($run, 'staff_id') === $actor->id,
            'canPublish' => $actor->hasPermission('content.publish'),
            'expectedRevisionId' => $baseId,
            'currentRevisionId' => $currentId,
            'pageId' => $pageId,
            'pageVersion' => (int) $this->string($page, 'version'),
            'publishedRevision' => (int) $this->string($page, 'published_revision'),
            'prompt' => $this->string($run, 'prompt'),
            'model' => $this->string($run, 'model'),
            'candidate' => $candidate,
            'base' => $this->base($this->json($base, 'payload')),
            'usage' => $this->usage($usage),
            'warnings' => $this->warnings($stale),
        ];
    }

    private function assertAuthor(StaffAccount $actor): void
    {
        if (! $actor->hasPermission('ai.use')
            || DB::table('ai_authors')->where('staff_id', $actor->id)->first() === null) {
            throw new AuthorizationException;
        }
    }

    private function assertRunId(string $runId): void
    {
        if (! Str::isUuid($runId) || $runId !== strtolower($runId)) {
            abort(404);
        }
    }

    /** @return list<string> */
    private function warnings(bool $stale): array
    {
        $warnings = [
            'Price, stock and campaign facts stay merchant-authored; review product claims before publishing.',
            'Applying writes a content draft only. Publishing stays a separate permission and action.',
        ];
        if ($stale) {
            $warnings[] = 'This page changed since generation. Apply is blocked until you generate from the current revision.';
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function document(array $payload): array
    {
        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function base(array $payload): array
    {
        return [
            'title' => is_string($payload['title'] ?? null) ? $payload['title'] : '',
            'slug' => is_string($payload['slug'] ?? null) ? $payload['slug'] : '',
            'path' => is_string($payload['path'] ?? null) ? $payload['path'] : '',
        ];
    }

    /** @return array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null, known: bool} */
    private function usage(?object $entry): array
    {
        if ($entry === null) {
            return ['input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null, 'known' => false];
        }
        $known = $this->string($entry, 'usage_known') === '1' || $this->string($entry, 'usage_known') === 'true';
        $input = $this->nullableInt($entry, 'input_tokens');
        $output = $this->nullableInt($entry, 'output_tokens');
        $total = $this->nullableInt($entry, 'total_tokens');

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
            'known' => $known && $input !== null && $output !== null && $total !== null,
        ];
    }

    private function nullableInt(object $row, string $key): ?int
    {
        $value = get_object_vars($row)[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /** @return array<string, mixed> */
    private function json(object $row, string $key): array
    {
        $value = get_object_vars($row)[$key] ?? null;
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('Invalid stored document.');
        }
        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid stored document.');
        }
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Invalid stored document.');
        }

        return $decoded;
    }

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
