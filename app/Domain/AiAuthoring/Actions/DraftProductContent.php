<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Actions;

use App\Domain\AiAuthoring\Data\AiAuthoringRequest;
use App\Domain\AiAuthoring\Exceptions\AiAuthoringRejected;
use App\Domain\AiAuthoring\Exceptions\AiDraftConflict;
use App\Domain\Catalog\Exceptions\CatalogProductNotFound;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;

/**
 * Proposes merchant-reviewed product title and description (AI-02).
 * Price and stock remain authoritative core fields.
 */
final class DraftProductContent
{
    public function __construct(
        private readonly DispatchAuthoringRun $runs,
        private readonly ValidateCandidate $validate,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function queue(StaffAccount $actor, array $input): string
    {
        $this->assertMerchant($actor);
        $data = Validator::make($input, [
            'product_id' => ['required', 'uuid'],
            'prompt' => ['required', 'string', 'min:1', 'max:4000'],
            'locale' => ['required', 'string', 'max:16'],
            'max_output_tokens' => ['required', 'integer', 'min:1', 'max:8192'],
        ])->validate();
        $productId = strtolower((string) $data['product_id']);
        $product = DB::table('products')->where('id', $productId)->first();
        if ($product === null) {
            throw new CatalogProductNotFound;
        }

        return $this->runs->queue($actor, [
            'operation' => AiAuthoringRequest::PRODUCT_COPY,
            'prompt' => (string) $data['prompt'],
            'locale' => (string) $data['locale'],
            'product_ids' => (string) $product->status === 'published' ? [$productId] : [],
            'subject_id' => $productId,
            'expected_version' => (int) $product->version,
            'max_output_tokens' => (int) $data['max_output_tokens'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{draft_id: string, product_id: string, version: int, status: string, title: string}
     */
    public function apply(StaffAccount $actor, string $draftId, array $input): array
    {
        $this->assertDraftId($draftId);
        $data = Validator::make($input, [
            'expected_version' => ['required', 'integer', 'min:1'],
            'document' => ['required', 'array'],
        ])->validate();
        $document = $data['document'];
        if (! is_array($document)) {
            throw ValidationException::withMessages(['document' => 'Provide a product copy document.']);
        }

        return DB::transaction(function () use ($actor, $draftId, $data, $document): array {
            $draft = DB::table('ai_drafts')->where('id', $draftId)->lockForUpdate()->first();
            if ($draft === null) {
                abort(404);
            }
            $run = DB::table('ai_runs')->where('id', $this->string($draft, 'run_id'))->lockForUpdate()->first();
            if ($run === null || $this->string($run, 'operation') !== AiAuthoringRequest::PRODUCT_COPY) {
                abort(404);
            }
            $this->assertMerchant($actor, true);
            $productId = $this->string($run, 'subject_id');
            $product = DB::table('products')->where('id', $productId)->lockForUpdate()->first();
            if ($product === null) {
                abort(404);
            }
            if ($this->string($draft, 'status') === 'applied') {
                return $this->outcome($draftId, $product);
            }
            if ($this->string($draft, 'status') !== 'candidate') {
                throw new AiAuthoringRejected;
            }
            $currentVersion = (int) $this->string($product, 'version');
            if ((int) $data['expected_version'] !== $currentVersion || (int) $this->string($run, 'expected_version') !== $currentVersion) {
                throw new AiDraftConflict((string) $currentVersion);
            }
            $normalized = $this->validated($run, $document);
            $title = $normalized['title'] ?? null;
            $description = $normalized['description'] ?? null;
            if (! is_string($title) || ! is_string($description)) {
                throw ValidationException::withMessages(['document' => 'The draft is not valid product copy.']);
            }
            $version = $currentVersion + 1;
            $now = now();
            DB::table('products')->where('id', $productId)->update([
                'title' => $title,
                'description' => $description,
                'version' => $version,
                'updated_at' => $now,
            ]);
            DB::table('ai_drafts')->where('id', $draftId)->update([
                'payload' => json_encode($normalized, JSON_THROW_ON_ERROR),
                'status' => 'applied',
                'updated_at' => $now,
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:ai-product-copy:'.$draftId.':'.$version, [
                'action' => 'ai.product_copy.applied',
                'subject_type' => 'product',
                'subject_id' => $productId,
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['draft_id' => $draftId, 'run_id' => $this->string($run, 'id'), 'version' => $version],
            ]);
            $fresh = DB::table('products')->where('id', $productId)->first();
            if ($fresh === null) {
                abort(404);
            }

            return $this->outcome($draftId, $fresh);
        });
    }

    /**
     * @return array{
     *     runId: string,
     *     draftId: string,
     *     status: string,
     *     operation: string,
     *     canApply: bool,
     *     expectedVersion: int,
     *     candidate: array<string, mixed>,
     *     base: array{title: string, description: string},
     *     usage: array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null, known: bool},
     *     warnings: list<string>,
     *     conflicts: list<array{key: string, message: string}>,
     *     migration: null
     * }
     */
    public function review(StaffAccount $actor, string $runId): array
    {
        $this->assertMerchant($actor);
        if (! Str::isUuid($runId) || $runId !== strtolower($runId)) {
            abort(404);
        }
        $run = DB::table('ai_runs')->where('id', $runId)->first();
        $draft = $run === null ? null : DB::table('ai_drafts')->where('run_id', $runId)->first();
        if ($run === null || $draft === null || $this->string($run, 'operation') !== AiAuthoringRequest::PRODUCT_COPY) {
            abort(404);
        }
        $product = DB::table('products')->where('id', $this->string($run, 'subject_id'))->first();
        if ($product === null) {
            abort(404);
        }
        $status = $this->string($draft, 'status');
        $current = (int) $this->string($product, 'version');
        $stale = $current !== (int) $this->string($run, 'expected_version');

        return [
            'runId' => $runId,
            'draftId' => $this->string($draft, 'id'),
            'status' => $status,
            'operation' => AiAuthoringRequest::PRODUCT_COPY,
            'canApply' => $status === 'candidate' && ! $stale && $actor->hasPermission('catalog.write'),
            'expectedVersion' => $current,
            'candidate' => $this->payload($draft),
            'base' => [
                'title' => $this->string($product, 'title'),
                'description' => $this->string($product, 'description'),
            ],
            'usage' => $this->usage($this->string($run, 'intent_id')),
            'warnings' => [
                'Price and stock stay merchant-authored core fields. Review copy before applying.',
                'Applying updates title and description only. It does not change price, stock or publication.',
            ],
            'conflicts' => [],
            'migration' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function validated(object $run, array $document): array
    {
        try {
            return $this->validate->productCopy($document, $this->string($run, 'subject_id'));
        } catch (InvalidArgumentException|JsonException|ValidationException) {
            throw ValidationException::withMessages(['document' => 'The draft is not valid product copy.']);
        }
    }

    /** @return array{draft_id: string, product_id: string, version: int, status: string, title: string} */
    private function outcome(string $draftId, object $product): array
    {
        return [
            'draft_id' => $draftId,
            'product_id' => $this->string($product, 'id'),
            'version' => (int) $this->string($product, 'version'),
            'status' => $this->string($product, 'status'),
            'title' => $this->string($product, 'title'),
        ];
    }

    private function assertMerchant(StaffAccount $actor, bool $lock = false): void
    {
        $authors = DB::table('ai_authors')->where('staff_id', $actor->id);
        if ($lock) {
            $authors->lockForUpdate();
        }
        if (! $actor->hasPermission('ai.use') || ! $actor->hasPermission('catalog.write') || $authors->first() === null) {
            throw new AuthorizationException;
        }
    }

    private function assertDraftId(string $draftId): void
    {
        if (! Str::isUuid($draftId) || $draftId !== strtolower($draftId)) {
            abort(404);
        }
    }

    /** @return array<string, mixed> */
    private function payload(object $row): array
    {
        $raw = get_object_vars($row)['payload'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            throw new InvalidArgumentException('Invalid stored document.');
        }
        $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Invalid stored document.');
        }

        return $decoded;
    }

    /** @return array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null, known: bool} */
    private function usage(string $intentId): array
    {
        $entry = DB::table('ai_usage_entries')->where('intent_id', $intentId)->first();
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

    private function string(object $row, string $key): string
    {
        $value = get_object_vars($row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
