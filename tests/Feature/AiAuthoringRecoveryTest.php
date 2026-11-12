<?php

declare(strict_types=1);

use App\Domain\AiAuthoring\Actions\DispatchAuthoringRun;
use App\Domain\AiAuthoring\Actions\SaveAiSettings;
use App\Domain\AiAuthoring\Adapters\SelectedProvider;
use App\Domain\AiAuthoring\Support\AiModels;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\ThemesContent\Actions\SaveContent;
use App\Jobs\GenerateAuthoringDraft;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
});

afterEach(function (): void {
    foreach ($GLOBALS['t078Zips'] ?? [] as $path) {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
    $GLOBALS['t078Zips'] = [];
});

/**
 * @return array{owner: StaffAccount, editor: StaffAccount, product_id: string, page_id: string, revision_id: string}
 */
function t078Store(bool $enableAi = true): array
{
    $owner = catalogInstalledOwner();
    $editor = new StaffAccount;
    $editor->forceFill([
        'name' => 'Content Editor',
        'email' => 'editor-recovery@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'content_editor',
        'permissions' => StaffPermissions::forRole('content_editor'),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $editor = $editor->fresh();
    if ($enableAi) {
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
    }
    $image = catalogReadyImage($owner->id);
    $published = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published',
        'description' => 'A public lamp for the workbench.',
    ]));
    $page = app(SaveContent::class)->savePage($owner, [
        'title' => 'Lighting guide',
        'slug' => 'lighting-guide',
        'status' => 'draft',
    ]);
    $revisionId = (string) DB::table('content_revisions')->where('subject_id', $page['id'])->value('id');

    return [
        'owner' => $owner,
        'editor' => $editor,
        'product_id' => $published['id'],
        'page_id' => $page['id'],
        'revision_id' => $revisionId,
    ];
}

/** @param array<string, mixed> $overrides */
function t078Generate(array $store, array $overrides = []): array
{
    return array_replace([
        'prompt' => 'Draft a studio lighting guide from the selected products.',
        'locale' => 'en-US',
        'product_ids' => [$store['product_id']],
        'page_id' => $store['page_id'],
        'max_output_tokens' => 1024,
        'intent_id' => (string) Str::uuid(),
    ], $overrides);
}

function t078Page(string $productId, string $text = 'Choose lighting that suits your space.'): array
{
    return [
        'schemaVersion' => '1.0',
        'kind' => 'page',
        'title' => 'Studio lighting',
        'templateKey' => 'page.default',
        'sections' => [
            ['id' => 'intro', 'type' => 'hero', 'version' => 1, 'settings' => ['heading' => 'Light your studio', 'alignment' => 'left']],
            ['id' => 'copy', 'type' => 'rich-text', 'version' => 1, 'settings' => ['text' => $text]],
            ['id' => 'products', 'type' => 'product-grid', 'version' => 1, 'settings' => ['heading' => 'Explore lighting', 'limit' => 4], 'binding' => ['kind' => 'products', 'publicIds' => [$productId]]],
        ],
    ];
}

/** @param array<string, mixed> $page */
function t078Envelope(string $intentId, array $page, int $input = 40, int $output = 80): array
{
    return [
        'id' => 'resp_fixture_recovery',
        'model' => 'gpt-4o-mini-2024-07-18',
        'status' => 'completed',
        'error' => null,
        'incomplete_details' => null,
        'metadata' => ['intent_id' => $intentId],
        'output' => [[
            'id' => 'msg_fixture_recovery',
            'type' => 'message',
            'role' => 'assistant',
            'status' => 'completed',
            'content' => [[
                'type' => 'output_text',
                'text' => json_encode($page, JSON_THROW_ON_ERROR),
                'annotations' => [],
            ]],
        ]],
        'usage' => ['input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $input + $output],
    ];
}

function t078FakeSuccess(array $page): void
{
    Http::fake(function (Request $request) use ($page): mixed {
        $payload = $request->data();
        $intent = is_array($payload['metadata'] ?? null) ? (string) ($payload['metadata']['intent_id'] ?? '') : '';

        return Http::response(t078Envelope($intent, $page), 200);
    });
}

/**
 * @param  array<string, mixed>  $manifest
 */
function t078Package(array $manifest): UploadedFile
{
    $path = sys_get_temp_dir().'/mrktflow-t078-'.Str::uuid().'.zip';
    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $zip->close();
    $GLOBALS['t078Zips'][] = $path;

    return new UploadedFile($path, 'template.zip', 'application/zip', null, true);
}

it('AiAuthoringRecovery walks generate review apply and publish as one continuous HTTP journey', function (): void {
    $store = t078Store();
    t078FakeSuccess(t078Page($store['product_id']));
    $this->actingAs($store['owner'], 'staff')->withSession(catalogStaffSession($store['owner']));
    $this->get('/')->assertStatus(503)->assertSee('This store is getting ready');

    $studio = $this->get('/admin/ai/studio');
    $studio->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AiStudio')
        ->has('pages', 1)
        ->where('pages.0.title', 'Lighting guide')
        ->has('products', 1)
        ->where('canPublish', true));
    expect($studio->getContent())->toContain('Lighting guide');

    $intent = (string) Str::uuid();
    $created = $this->post('/admin/ai/studio', t078Generate($store, ['intent_id' => $intent]));
    $runId = (string) DB::table('ai_runs')->where('intent_id', $intent)->value('id');
    expect($runId)->not->toBe('');
    $created->assertRedirect('/admin/ai/studio/'.$runId);

    $review = $this->get('/admin/ai/studio/'.$runId);
    $review->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AiStudio')
        ->where('status', 'candidate')
        ->where('statusMessage', 'Draft ready for review.')
        ->where('canApply', true)
        ->where('canPublish', true)
        ->where('candidate.title', 'Studio lighting'));
    expect($review->getContent())->toContain('Light your studio')
        ->and($review->getContent())->toContain('120')
        ->and($review->getContent())->not->toContain('sk-test-mrktflow-authoring-secret');

    $this->postJson('/admin/ai/studio/'.$runId.'/apply', [
        'expected_revision_id' => $store['revision_id'],
        'document' => t078Page($store['product_id'], 'Merchant-reviewed lighting copy.'),
    ])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('published_revision', 0);

    expect((string) DB::table('pages')->where('id', $store['page_id'])->value('status'))->toBe('draft');
    $this->get('/pages/lighting-guide')->assertNotFound();

    $this->post('/admin/content/publish', [
        'kind' => 'page',
        'id' => $store['page_id'],
        'version' => 2,
    ])->assertRedirect();
    $this->get('/pages/lighting-guide')->assertOk()
        ->assertSee('Studio lighting')
        ->assertSee('Merchant-reviewed lighting copy.');
    $this->get('/')->assertStatus(503)->assertDontSee('Merchant-reviewed lighting copy.');
});

it('AiAuthoringRecovery keeps prior drafts and budget bounds after cancel failed and duplicate runs', function (): void {
    $store = t078Store();
    t078FakeSuccess(t078Page($store['product_id']));
    $this->actingAs($store['owner'], 'staff')->withSession(catalogStaffSession($store['owner']));

    $keptIntent = (string) Str::uuid();
    $this->post('/admin/ai/studio', t078Generate($store, ['intent_id' => $keptIntent]))->assertRedirect();
    $keptRun = (string) DB::table('ai_runs')->where('intent_id', $keptIntent)->value('id');
    $this->postJson('/admin/ai/studio/'.$keptRun.'/apply', [
        'expected_revision_id' => $store['revision_id'],
        'document' => t078Page($store['product_id']),
    ])->assertOk()->assertJsonPath('version', 2);
    $keptDraft = (string) DB::table('ai_drafts')->where('run_id', $keptRun)->value('id');
    $pageBefore = DB::table('pages')->where('id', $store['page_id'])->first();

    $queue = Queue::getFacadeRoot();
    Queue::fake();
    $cancelIntent = (string) Str::uuid();
    $this->post('/admin/ai/studio', t078Generate($store, [
        'intent_id' => $cancelIntent,
        'prompt' => 'A second lighting outline that will be cancelled.',
    ]))->assertRedirect();
    $cancelRun = (string) DB::table('ai_runs')->where('intent_id', $cancelIntent)->value('id');
    Queue::assertPushed(GenerateAuthoringDraft::class);
    $this->post('/admin/ai/studio/'.$cancelRun.'/cancel')->assertRedirect('/admin/ai/studio/'.$cancelRun);
    $cancelled = $this->get('/admin/ai/studio/'.$cancelRun);
    $cancelled->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('status', 'cancelled')
        ->where('statusMessage', 'Generation cancelled.')
        ->where('canApply', false)
        ->where('candidate', null));
    expect((string) DB::table('ai_usage_reservations')->where('intent_id', $cancelIntent)->value('status'))->toBe('released');
    (new GenerateAuthoringDraft($cancelRun))->handle(app(DispatchAuthoringRun::class), app(SelectedProvider::class));
    Http::assertSentCount(1);

    Queue::swap($queue);
    Http::fake(fn () => Http::response(['error' => ['message' => 'rate']], 429));
    $failedIntent = (string) Str::uuid();
    $this->post('/admin/ai/studio', t078Generate($store, [
        'intent_id' => $failedIntent,
        'prompt' => 'A third lighting outline that the provider rate-limits.',
    ]))->assertRedirect();
    $failedRun = (string) DB::table('ai_runs')->where('intent_id', $failedIntent)->value('id');
    $failed = $this->get('/admin/ai/studio/'.$failedRun);
    $failed->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('status', 'failed')
        ->where('statusMessage', 'Provider rate limited.')
        ->where('canApply', false));
    expect((string) DB::table('ai_usage_reservations')->where('intent_id', $failedIntent)->value('status'))->toBe('pending_unknown');
    (new GenerateAuthoringDraft($failedRun))->handle(app(DispatchAuthoringRun::class), app(SelectedProvider::class));
    expect((int) DB::table('ai_runs')->where('id', $failedRun)->value('provider_attempts'))->toBe(1);

    $duplicate = $this->post('/admin/ai/studio', t078Generate($store, [
        'intent_id' => $keptIntent,
        'prompt' => 'Draft a studio lighting guide from the selected products.',
    ]));
    $duplicate->assertRedirect('/admin/ai/studio/'.$keptRun);
    expect(DB::table('ai_runs')->count())->toBe(3)
        ->and((string) DB::table('ai_drafts')->where('id', $keptDraft)->value('status'))->toBe('applied')
        ->and((int) DB::table('pages')->where('id', $store['page_id'])->value('version'))->toBe((int) $pageBefore->version)
        ->and((string) DB::table('pages')->where('id', $store['page_id'])->value('title'))->toBe((string) $pageBefore->title);
    $this->postJson('/admin/ai/studio/'.$keptRun.'/apply', [
        'expected_revision_id' => $store['revision_id'],
        'document' => t078Page($store['product_id']),
    ])->assertOk()->assertJsonPath('version', 2);
    (new GenerateAuthoringDraft($keptRun))->handle(app(DispatchAuthoringRun::class), app(SelectedProvider::class));
    expect((int) DB::table('pages')->where('id', $store['page_id'])->value('version'))->toBe(2)
        ->and((string) DB::table('ai_drafts')->where('id', $keptDraft)->value('status'))->toBe('applied');
    $this->get('/pages/lighting-guide')->assertNotFound();
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringRecovery lets owners import templates while built-in AI is off', function (): void {
    $store = t078Store(false);
    expect((int) DB::table('ai_settings')->where('id', 1)->value('enabled'))->toBe(0);
    $this->actingAs($store['owner'], 'staff')->withSession(catalogStaffSession($store['owner']));
    $this->from('/admin/ai')->post('/admin/ai/studio', t078Generate($store))
        ->assertRedirect('/admin/ai')
        ->assertSessionHasErrors('prompt');
    $this->postJson('/admin/ai/studio', t078Generate($store))->assertStatus(422)
        ->assertJsonValidationErrors('prompt');
    $staged = $this->post('/admin/content/import', ['file' => t078Package([
        'schemaVersion' => '1.0',
        'kind' => 'template-package',
        'documents' => [[
            'kind' => 'section_draft',
            'page' => 'search',
            'slot' => 'content',
            'sections' => [[
                'id' => 'intro',
                'type' => 'hero',
                'version' => 1,
                'settings' => ['heading' => 'Imported without AI'],
            ]],
        ]],
        'media' => [],
        'references' => ['products' => [], 'categories' => []],
    ])]);
    $staged->assertRedirect();
    $importId = (string) DB::table('content_import_files')->value('id');
    $this->post('/admin/content/import/'.$importId.'/apply')->assertRedirect('/admin/content/import/'.$importId);
    $draft = $this->getJson('/admin/content-editor/draft?page=search&slot=content')->assertOk();
    expect($draft->json('sections.0.settings.heading'))->toBe('Imported without AI')
        ->and((int) $draft->json('publishedRevision'))->toBe(0)
        ->and(DB::table('ai_runs')->count())->toBe(0);
    $this->get('/')->assertStatus(503)->assertDontSee('Imported without AI');
});

it('AiAuthoringRecovery HTTP keeps privileged staff behind two-factor and rejects customers', function (): void {
    $store = t078Store();
    t078FakeSuccess(t078Page($store['product_id']));
    $payload = t078Generate($store);
    $this->post('/admin/ai/studio', $payload)->assertRedirect('/admin/login');
    $this->postJson('/admin/ai/studio', $payload)->assertUnauthorized();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper',
        'email' => 'shopper-recovery@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->postJson('/admin/ai/studio', $payload)->assertUnauthorized();
    auth('customer')->logout();
    $this->flushSession();
    $this->actingAs($store['owner'], 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/ai/studio')->assertRedirect('/admin/two-factor');
    $this->post('/admin/ai/studio', $payload)->assertRedirect('/admin/two-factor');
    expect(DB::table('ai_runs')->count())->toBe(0);
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringRecovery records capped live-provider smoke as pending without credentials', function (): void {
    $key = getenv('MRKTFLOW_AI_SMOKE_KEY');
    if (! is_string($key) || $key === '') {
        expect(true)->toBeTrue();

        return;
    }
    $store = t078Store();
    Http::allowStrayRequests();
    $this->actingAs($store['owner'], 'staff')->withSession(catalogStaffSession($store['owner']));
    $this->post('/admin/ai/studio', t078Generate($store, [
        'max_output_tokens' => 64,
        'prompt' => 'Return one short lighting hero and rich text for a reviewable page draft.',
    ]))->assertRedirect();
    $run = DB::table('ai_runs')->orderByDesc('created_at')->first();
    expect($run)->not->toBeNull()
        ->and(in_array((string) $run->status, ['completed', 'failed', 'unknown'], true))->toBeTrue();
    $this->get('/')->assertStatus(503);
})->skip(
    ! is_string(getenv('MRKTFLOW_AI_SMOKE_KEY')) || getenv('MRKTFLOW_AI_SMOKE_KEY') === '',
    'Capped live-provider smoke pending: MRKTFLOW_AI_SMOKE_KEY is not configured.',
);
