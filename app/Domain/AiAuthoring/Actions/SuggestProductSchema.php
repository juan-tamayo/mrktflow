<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Actions;

use App\Domain\AiAuthoring\Data\AiAuthoringRequest;
use App\Domain\AiAuthoring\Data\ProductSchemaDraft;
use App\Domain\AiAuthoring\Exceptions\AiAuthoringRejected;
use App\Domain\AiAuthoring\Exceptions\AiDraftConflict;
use App\Domain\Catalog\Actions\ApplySchemaMigration;
use App\Domain\Catalog\Actions\PreviewSchemaMigration;
use App\Domain\Catalog\Actions\SaveProductSchema;
use App\Domain\Catalog\Exceptions\CatalogProductNotFound;
use App\Domain\Catalog\Support\ProductSchemaWriter;
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
 * Proposes typed product schemas for merchant review (AI-02, CAT-04).
 * Semantic-key conflicts and migrations stay explicit; price/stock are not schema fields.
 */
final class SuggestProductSchema
{
    public function __construct(
        private readonly DispatchAuthoringRun $runs,
        private readonly ValidateCandidate $validate,
        private readonly PreviewSchemaMigration $preview,
        private readonly ApplySchemaMigration $migrate,
        private readonly SaveProductSchema $save,
        private readonly ProductSchemaWriter $writer,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function queue(StaffAccount $actor, array $input): string
    {
        $this->assertMerchant($actor);
        $data = Validator::make($input, [
            'product_id' => ['required', 'uuid'],
            'schema_id' => ['nullable', 'uuid'],
            'prompt' => ['required', 'string', 'min:1', 'max:4000'],
            'locale' => ['required', 'string', 'max:16'],
            'max_output_tokens' => ['required', 'integer', 'min:1', 'max:8192'],
        ])->validate();
        $productId = strtolower((string) $data['product_id']);
        $product = DB::table('products')->where('id', $productId)->first();
        if ($product === null) {
            throw new CatalogProductNotFound;
        }
        $schemaId = isset($data['schema_id']) && is_string($data['schema_id']) && $data['schema_id'] !== ''
            ? strtolower($data['schema_id'])
            : $this->schemaIdFromProduct($product);
        $expected = 1;
        if ($schemaId !== null) {
            $schema = DB::table('product_schemas')->where('id', $schemaId)->first();
            if ($schema === null) {
                abort(404);
            }
            $expected = (int) $schema->version;
        }

        return $this->runs->queue($actor, [
            'operation' => AiAuthoringRequest::PRODUCT_SCHEMA,
            'prompt' => (string) $data['prompt'],
            'locale' => (string) $data['locale'],
            'product_ids' => (string) $product->status === 'published' ? [$productId] : [],
            'subject_id' => $productId,
            'schema_id' => $schemaId,
            'expected_version' => $expected,
            'max_output_tokens' => (int) $data['max_output_tokens'],
        ]);
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
     *     migration: array{affected_products: int, invalid_values: list<array<string, mixed>>, comparison_changes: list<array<string, mixed>>, incompatible_fields: list<array{key: string, message: string}>, compatible: bool, can_apply: bool}|null
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
        if ($run === null || $draft === null || $this->string($run, 'operation') !== AiAuthoringRequest::PRODUCT_SCHEMA) {
            abort(404);
        }
        $product = DB::table('products')->where('id', $this->string($run, 'subject_id'))->first();
        if ($product === null) {
            abort(404);
        }
        $candidate = $this->payload($draft);
        $catalog = $this->catalogInput($run, $candidate);
        $conflicts = $this->conflicts($catalog['attributes']);
        $migration = null;
        $schemaId = $this->string($run, 'schema_id');
        if ($schemaId !== '') {
            $migration = $this->preview->preview($actor, $catalog);
            $conflicts = $migration['incompatible_fields'];
        }
        $status = $this->string($draft, 'status');
        $canMigrate = $migration === null || $migration['can_apply'];

        return [
            'runId' => $runId,
            'draftId' => $this->string($draft, 'id'),
            'status' => $status,
            'operation' => AiAuthoringRequest::PRODUCT_SCHEMA,
            'canApply' => $status === 'candidate' && $conflicts === [] && $canMigrate && $actor->hasPermission('catalog.write'),
            'expectedVersion' => $schemaId === '' ? 1 : (int) $this->string($run, 'expected_version'),
            'candidate' => $candidate,
            'base' => [
                'title' => $this->string($product, 'title'),
                'description' => $this->string($product, 'description'),
            ],
            'usage' => $this->usage($this->string($run, 'intent_id')),
            'warnings' => [
                'Price and stock stay merchant-authored core fields. Review semantic keys before applying.',
                'An existing semantic key cannot change type or comparison meaning.',
            ],
            'conflicts' => $conflicts,
            'migration' => $migration,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, key: string, version: int, schema_version: int, comparison_group: string}
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
            throw ValidationException::withMessages(['document' => 'Provide a product schema document.']);
        }

        return DB::transaction(function () use ($actor, $draftId, $data, $document): array {
            $draft = DB::table('ai_drafts')->where('id', $draftId)->lockForUpdate()->first();
            if ($draft === null) {
                abort(404);
            }
            $run = DB::table('ai_runs')->where('id', $this->string($draft, 'run_id'))->lockForUpdate()->first();
            if ($run === null || $this->string($run, 'operation') !== AiAuthoringRequest::PRODUCT_SCHEMA) {
                abort(404);
            }
            $this->assertMerchant($actor, true);
            if ($this->string($draft, 'status') === 'applied') {
                $schemaId = $this->string($run, 'schema_id');
                $schema = DB::table('product_schemas')->where('id', $schemaId)->first();
                if ($schema === null) {
                    abort(404);
                }

                return $this->outcome($schema);
            }
            if ($this->string($draft, 'status') !== 'candidate') {
                throw new AiAuthoringRejected;
            }
            $normalized = $this->validated($document);
            $catalog = $this->catalogInput($run, $normalized);
            $schemaId = $this->string($run, 'schema_id');
            if ($schemaId !== '') {
                $schema = DB::table('product_schemas')->where('id', $schemaId)->lockForUpdate()->first();
                if ($schema === null) {
                    abort(404);
                }
                if ((int) $data['expected_version'] !== (int) $schema->version || (int) $this->string($run, 'expected_version') !== (int) $schema->version) {
                    throw new AiDraftConflict((string) $schema->version);
                }
                $assigned = DB::table('products')->where('schema_version_id', $schema->current_version_id)->lockForUpdate()->exists();
                $saved = $assigned
                    ? $this->migrate->apply($actor, $catalog)
                    : $this->save->save($actor, $catalog);
            } else {
                $saved = $this->save->save($actor, $catalog);
            }
            DB::table('ai_drafts')->where('id', $draftId)->update([
                'payload' => json_encode($normalized, JSON_THROW_ON_ERROR),
                'status' => 'applied',
                'updated_at' => now(),
            ]);
            if ($schemaId === '') {
                DB::table('ai_runs')->where('id', $this->string($run, 'id'))->update([
                    'schema_id' => $saved['id'],
                    'updated_at' => now(),
                ]);
            }
            app(RecordEvent::class)->record('platform.audit.recorded', 'audit:ai-product-schema:'.$draftId.':'.$saved['version'], [
                'action' => 'ai.product_schema.applied',
                'subject_type' => 'product_schema',
                'subject_id' => $saved['id'],
                'actor_type' => 'staff',
                'actor_id' => $actor->id,
                'metadata' => ['draft_id' => $draftId, 'run_id' => $this->string($run, 'id'), 'schema_version' => $saved['schema_version']],
            ]);

            return $saved;
        });
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function validated(array $document): array
    {
        try {
            return $this->validate->productSchema($document);
        } catch (InvalidArgumentException|JsonException|ValidationException) {
            throw ValidationException::withMessages(['document' => 'The draft is not a valid product schema.']);
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function catalogInput(object $run, array $document): array
    {
        $draft = ProductSchemaDraft::fromJson(json_encode($document, JSON_THROW_ON_ERROR));
        $input = $draft->catalogInput();
        $schemaId = $this->string($run, 'schema_id');
        if ($schemaId !== '') {
            $input['id'] = $schemaId;
            $input['version'] = (int) $this->string($run, 'expected_version');
        }

        return $input;
    }

    /**
     * @param  list<array<string, mixed>>  $attributes
     * @return list<array{key: string, message: string}>
     */
    private function conflicts(array $attributes): array
    {
        $conflicts = [];
        foreach ($attributes as $attribute) {
            $conflict = $this->writer->incompatibility($attribute);
            if ($conflict !== null) {
                $conflicts[] = $conflict;
            }
        }

        return $conflicts;
    }

    private function schemaIdFromProduct(object $product): ?string
    {
        $versionId = $this->string($product, 'schema_version_id');
        if ($versionId === '') {
            return null;
        }
        $schemaId = DB::table('product_schema_versions')->where('id', $versionId)->value('product_schema_id');

        return is_string($schemaId) ? $schemaId : null;
    }

    /**
     * @return array{id: string, key: string, version: int, schema_version: int, comparison_group: string}
     */
    private function outcome(object $schema): array
    {
        $versionId = $this->string($schema, 'current_version_id');
        $schemaVersion = (int) DB::table('product_schema_versions')->where('id', $versionId)->value('version');

        return [
            'id' => $this->string($schema, 'id'),
            'key' => $this->string($schema, 'semantic_key'),
            'version' => (int) $this->string($schema, 'version'),
            'schema_version' => $schemaVersion,
            'comparison_group' => $this->string($schema, 'comparison_group'),
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
