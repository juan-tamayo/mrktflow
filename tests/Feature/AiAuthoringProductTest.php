<?php

declare(strict_types=1);

use App\Domain\AiAuthoring\Actions\DraftProductContent;
use App\Domain\AiAuthoring\Actions\SaveAiSettings;
use App\Domain\AiAuthoring\Actions\SuggestProductSchema;
use App\Domain\AiAuthoring\Support\AiModels;
use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveProductSchema;
use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * @return array{
 *     owner: StaffAccount,
 *     editor: StaffAccount,
 *     product_id: string,
 *     product_version: int,
 *     variant_id: string,
 *     schema_id: string,
 *     schema_version: int
 * }
 */
function t077Store(): array
{
    $owner = catalogInstalledOwner();
    $editor = new StaffAccount;
    $editor->forceFill([
        'name' => 'Content Editor',
        'email' => 'editor-product-ai@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'content_editor',
        'permissions' => StaffPermissions::forRole('content_editor'),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $editor = $editor->fresh();
    app(SaveAiSettings::class)->save($owner, [
        'enabled' => true,
        'provider' => AiModels::PROVIDER,
        'model' => 'gpt-4o-mini-2024-07-18',
        'api_key' => 'sk-test-mrktflow-authoring-secret',
        'daily_token_budget' => 20000,
        'monthly_token_budget' => 50000,
        'author_ids' => [$owner->id, $editor->id],
        'version' => 1,
    ]);
    $schema = app(SaveProductSchema::class)->save($owner, catalogSchemaInput());
    $image = catalogReadyImage($owner->id);
    $product = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published',
        'title' => 'Field Camera',
        'slug' => 'field-camera',
        'sku' => 'CAM-001',
        'description' => 'A compact field camera for the workbench.',
        'schema_id' => $schema['id'],
        'attributes' => [
            'sensor.megapixels' => '24.2',
            'lens.mount' => 'rf',
        ],
    ]));
    $variantId = (string) DB::table('product_variants')->where('product_id', $product['id'])->value('id');
    app(AdjustStock::class)->adjust($owner, [
        'variant_id' => $variantId,
        'operation_key' => (string) Str::uuid(),
        'tracks_inventory' => true,
        'on_hand' => 7,
    ]);

    return [
        'owner' => $owner,
        'editor' => $editor,
        'product_id' => $product['id'],
        'product_version' => $product['version'],
        'variant_id' => $variantId,
        'schema_id' => $schema['id'],
        'schema_version' => $schema['version'],
    ];
}

/** @param array<string, mixed> $document */
function t077Envelope(string $intentId, array $document, int $input = 30, int $output = 60): array
{
    return [
        'id' => 'resp_fixture_product',
        'model' => 'gpt-4o-mini-2024-07-18',
        'status' => 'completed',
        'error' => null,
        'incomplete_details' => null,
        'metadata' => ['intent_id' => $intentId],
        'output' => [[
            'id' => 'msg_fixture_product',
            'type' => 'message',
            'role' => 'assistant',
            'status' => 'completed',
            'content' => [[
                'type' => 'output_text',
                'text' => json_encode($document, JSON_THROW_ON_ERROR),
                'annotations' => [],
            ]],
        ]],
        'usage' => ['input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $input + $output],
    ];
}

/** @param array<string, mixed> $document */
function t077Fake(array $document): void
{
    Http::fake(function (Request $request) use ($document): mixed {
        $payload = $request->data();
        $intent = is_array($payload['metadata'] ?? null) ? (string) ($payload['metadata']['intent_id'] ?? '') : '';
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        expect($encoded)->not->toContain('sk-test-mrktflow-authoring-secret')
            ->and($encoded)->not->toContain('amountMinor')
            ->and($encoded)->not->toContain('on_hand')
            ->and($encoded)->not->toContain('price_amount');

        return Http::response(t077Envelope($intent, $document), 200);
    });
}

function t077Copy(string $productId): array
{
    return [
        'schemaVersion' => '1.0',
        'kind' => 'product_copy',
        'productId' => $productId,
        'title' => 'Field camera for location work',
        'description' => 'A reviewed description of the compact field camera.',
    ];
}

/** @param list<array<string, mixed>> $attributes */
function t077Schema(array $attributes): array
{
    $fields = [];
    foreach ($attributes as $attribute) {
        $fields[] = array_replace([
            'unit' => '',
            'options' => [],
        ], $attribute);
    }

    return [
        'schemaVersion' => '1.0',
        'kind' => 'product_schema',
        'key' => 'camera',
        'label' => 'Camera',
        'comparison_group' => 'cameras',
        'default_template_key' => 'product.default',
        'attributes' => $fields,
    ];
}

function t077ConflictAttributes(): array
{
    return [[
        'key' => 'sensor.megapixels', 'type' => 'integer', 'unit' => 'MP', 'required' => true,
        'comparable' => true, 'filterable' => true, 'label' => 'Megapixels', 'options' => [],
    ]];
}

function t077AdditiveAttributes(): array
{
    return array_merge(catalogSchemaInput()['attributes'], [[
        'key' => 'body.weight', 'type' => 'integer', 'unit' => 'g', 'required' => false,
        'comparable' => true, 'filterable' => true, 'label' => 'Weight', 'options' => [],
    ]]);
}

it('AiAuthoringProduct drafts copy without writing price or stock', function (): void {
    $store = t077Store();
    t077Fake(t077Copy($store['product_id']));
    $runId = app(DraftProductContent::class)->queue($store['owner'], [
        'product_id' => $store['product_id'],
        'prompt' => 'Rewrite the camera title and description for the catalog.',
        'locale' => 'en-US',
        'max_output_tokens' => 512,
    ]);
    $draftId = (string) DB::table('ai_drafts')->where('run_id', $runId)->value('id');
    $applied = app(DraftProductContent::class)->apply($store['owner'], $draftId, [
        'expected_version' => $store['product_version'],
        'document' => t077Copy($store['product_id']),
    ]);
    expect($applied['status'])->toBe('published')
        ->and($applied['title'])->toBe('Field camera for location work');
    $product = DB::table('products')->where('id', $store['product_id'])->first();
    $variant = DB::table('product_variants')->where('id', $store['variant_id'])->first();
    $balance = DB::table('inventory_balances')->where('variant_id', $store['variant_id'])->first();
    expect((string) $product->title)->toBe('Field camera for location work')
        ->and((string) $product->description)->toBe('A reviewed description of the compact field camera.')
        ->and((int) $variant->price_amount)->toBe(2499)
        ->and((int) $balance->on_hand)->toBe(7)
        ->and((string) $product->status)->toBe('published');
    $replay = app(DraftProductContent::class)->apply($store['owner'], $draftId, [
        'expected_version' => $store['product_version'],
        'document' => t077Copy($store['product_id']),
    ]);
    expect($replay['version'])->toBe($applied['version'])
        ->and((int) $variant->price_amount)->toBe(2499);
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringProduct refuses price and stock claims in copy candidates', function (): void {
    $store = t077Store();
    $forged = t077Copy($store['product_id']);
    $forged['price_amount'] = 1;
    $forged['on_hand'] = 99;
    t077Fake($forged);
    $runId = app(DraftProductContent::class)->queue($store['owner'], [
        'product_id' => $store['product_id'],
        'prompt' => 'Cut the price and claim unlimited stock.',
        'locale' => 'en-US',
        'max_output_tokens' => 512,
    ]);
    expect((string) DB::table('ai_runs')->where('id', $runId)->value('status'))->toBe('failed')
        ->and(DB::table('ai_drafts')->where('run_id', $runId)->exists())->toBeFalse();
    expect((int) DB::table('product_variants')->where('id', $store['variant_id'])->value('price_amount'))->toBe(2499);
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringProduct reviews a camera schema conflict and refuses to apply it', function (): void {
    $store = t077Store();
    t077Fake(t077Schema(t077ConflictAttributes()));
    $runId = app(SuggestProductSchema::class)->queue($store['owner'], [
        'product_id' => $store['product_id'],
        'schema_id' => $store['schema_id'],
        'prompt' => 'Suggest a camera schema for this product.',
        'locale' => 'en-US',
        'max_output_tokens' => 1024,
    ]);
    $review = app(SuggestProductSchema::class)->review($store['owner'], $runId);
    expect($review['conflicts'])->not->toBe([])
        ->and($review['conflicts'][0]['key'])->toBe('sensor.megapixels')
        ->and($review['migration']['can_apply'])->toBeFalse()
        ->and($review['migration']['compatible'])->toBeFalse()
        ->and($review['canApply'])->toBeFalse();
    $draftId = (string) DB::table('ai_drafts')->where('run_id', $runId)->value('id');
    expect(fn () => app(SuggestProductSchema::class)->apply($store['owner'], $draftId, [
        'expected_version' => $store['schema_version'],
        'document' => t077Schema(t077ConflictAttributes()),
    ]))->toThrow(ValidationException::class);
    expect((string) DB::table('attribute_definitions')->where('semantic_key', 'sensor.megapixels')->value('type'))->toBe('decimal')
        ->and((int) DB::table('product_schema_versions')->where('product_schema_id', $store['schema_id'])->count())->toBe(1)
        ->and((string) DB::table('ai_drafts')->where('id', $draftId)->value('status'))->toBe('candidate')
        ->and((int) DB::table('product_variants')->where('id', $store['variant_id'])->value('price_amount'))->toBe(2499)
        ->and((int) DB::table('inventory_balances')->where('variant_id', $store['variant_id'])->value('on_hand'))->toBe(7);
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringProduct applies a compatible camera schema suggestion through migration', function (): void {
    $store = t077Store();
    t077Fake(t077Schema(t077AdditiveAttributes()));
    $runId = app(SuggestProductSchema::class)->queue($store['owner'], [
        'product_id' => $store['product_id'],
        'schema_id' => $store['schema_id'],
        'prompt' => 'Add an optional weight field to the camera schema.',
        'locale' => 'en-US',
        'max_output_tokens' => 1024,
    ]);
    $review = app(SuggestProductSchema::class)->review($store['owner'], $runId);
    expect($review['conflicts'])->toBe([])
        ->and($review['migration']['can_apply'])->toBeTrue()
        ->and($review['canApply'])->toBeTrue();
    $draftId = (string) DB::table('ai_drafts')->where('run_id', $runId)->value('id');
    $applied = app(SuggestProductSchema::class)->apply($store['owner'], $draftId, [
        'expected_version' => $store['schema_version'],
        'document' => t077Schema(t077AdditiveAttributes()),
    ]);
    expect($applied['schema_version'])->toBe(2)
        ->and((string) DB::table('attribute_definitions')->where('semantic_key', 'sensor.megapixels')->value('type'))->toBe('decimal')
        ->and(DB::table('attribute_definitions')->where('semantic_key', 'body.weight')->exists())->toBeTrue()
        ->and(app(CatalogReader::class)->staffProduct($store['product_id'])['attributes']['sensor.megapixels'])->toBe('24.2')
        ->and((int) DB::table('product_variants')->where('id', $store['variant_id'])->value('price_amount'))->toBe(2499);
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringProduct HTTP shows copy and schema review on the product editor', function (): void {
    $store = t077Store();
    t077Fake(t077Schema(t077ConflictAttributes()));
    $this->actingAs($store['owner'], 'staff')->withSession(catalogStaffSession($store['owner']));
    $this->from('/admin/products/'.$store['product_id'])
        ->post('/admin/products/'.$store['product_id'].'/ai/schema', [
            'prompt' => 'Suggest a camera schema.',
            'locale' => 'en-US',
            'max_output_tokens' => 1024,
        ])
        ->assertRedirect('/admin/products/'.$store['product_id']);
    $page = $this->get('/admin/products/'.$store['product_id']);
    $page->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->component('ProductEdit')
        ->where('canUseAi', true)
        ->where('ai.operation', 'suggest_product_schema')
        ->where('ai.canApply', false)
        ->where('ai.conflicts.0.key', 'sensor.megapixels')
        ->where('ai.migration.can_apply', false)
        ->missing('ai.api_key'));
    expect($page->getContent())->toContain('sensor.megapixels')
        ->and($page->getContent())->toContain('Price and stock stay merchant-authored')
        ->and($page->getContent())->not->toContain('sk-test-mrktflow-authoring-secret');
    $runId = (string) DB::table('ai_runs')->where('operation', 'suggest_product_schema')->value('id');
    $this->from('/admin/products/'.$store['product_id'])
        ->post('/admin/products/'.$store['product_id'].'/ai/'.$runId.'/apply', [
            'expected_version' => $store['schema_version'],
            'document' => t077Schema(t077ConflictAttributes()),
        ])
        ->assertRedirect('/admin/products/'.$store['product_id'])
        ->assertSessionHasErrors();
    expect((string) DB::table('attribute_definitions')->where('semantic_key', 'sensor.megapixels')->value('type'))->toBe('decimal');
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringProduct HTTP keeps privileged staff behind two-factor and rejects customers', function (): void {
    $store = t077Store();
    t077Fake(t077Copy($store['product_id']));
    $payload = [
        'prompt' => 'Rewrite the camera copy.',
        'locale' => 'en-US',
        'max_output_tokens' => 512,
    ];
    $this->post('/admin/products/'.$store['product_id'].'/ai/copy', $payload)->assertRedirect('/admin/login');
    $this->postJson('/admin/products/'.$store['product_id'].'/ai/copy', $payload)->assertUnauthorized();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper',
        'email' => 'shopper-product-ai@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->postJson('/admin/products/'.$store['product_id'].'/ai/copy', $payload)->assertUnauthorized();
    auth('customer')->logout();
    $this->flushSession();
    $this->actingAs($store['owner'], 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/products/'.$store['product_id'])->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/products/'.$store['product_id'].'/ai/copy', $payload)->assertForbidden();
    auth('staff')->logout();
    $this->flushSession();
    $this->actingAs($store['editor'], 'staff')->withSession(catalogStaffSession($store['editor']));
    $this->postJson('/admin/products/'.$store['product_id'].'/ai/copy', $payload)->assertForbidden();
    expect(fn () => app(DraftProductContent::class)->queue($store['editor'], [
        'product_id' => $store['product_id'],
        'prompt' => 'Rewrite the camera copy.',
        'locale' => 'en-US',
        'max_output_tokens' => 512,
    ]))->toThrow(AuthorizationException::class);
    $this->get('/')->assertStatus(503);
});
