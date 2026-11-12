<?php

declare(strict_types=1);

use App\Domain\AiAuthoring\Actions\ApplyDraft;
use App\Domain\AiAuthoring\Actions\DispatchAuthoringRun;
use App\Domain\AiAuthoring\Actions\SaveAiSettings;
use App\Domain\AiAuthoring\Exceptions\AiDraftConflict;
use App\Domain\AiAuthoring\Support\AiModels;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\ThemesContent\Actions\PublishContent;
use App\Domain\ThemesContent\Actions\SaveContent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * @return array{owner: StaffAccount, editor: StaffAccount, product_id: string, page_id: string, revision_id: string}
 */
function t076Store(): array
{
    $owner = catalogInstalledOwner();
    $editor = new StaffAccount;
    $editor->forceFill([
        'name' => 'Content Editor',
        'email' => 'editor@example.test',
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
        'author_ids' => [$editor->id],
        'version' => 1,
    ]);
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
function t076Input(array $store, array $overrides = []): array
{
    return array_replace([
        'prompt' => 'Draft a studio lighting guide from the selected products.',
        'locale' => 'en-US',
        'product_ids' => [$store['product_id']],
        'base_revision_id' => $store['revision_id'],
        'max_output_tokens' => 1024,
    ], $overrides);
}

function t076Page(string $productId, string $text = 'Choose lighting that suits your space.'): array
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
function t076Envelope(string $intentId, string $model, array $page, int $input = 40, int $output = 80): array
{
    return [
        'id' => 'resp_fixture_apply',
        'model' => $model,
        'status' => 'completed',
        'error' => null,
        'incomplete_details' => null,
        'metadata' => ['intent_id' => $intentId],
        'output' => [[
            'id' => 'msg_fixture_apply',
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

function t076Queue(array $store, array $page): string
{
    Http::fake(function (Request $request) use ($page): mixed {
        $payload = $request->data();
        $intent = is_array($payload['metadata'] ?? null) ? (string) ($payload['metadata']['intent_id'] ?? '') : '';

        return Http::response(t076Envelope($intent, 'gpt-4o-mini-2024-07-18', $page), 200);
    });

    return app(DispatchAuthoringRun::class)->queue($store['editor'], t076Input($store));
}

it('AiAuthoringApply writes an editable candidate onto a matching draft revision without publishing', function (): void {
    $store = t076Store();
    expect($store['editor']->hasPermission('content.publish'))->toBeFalse()
        ->and($store['editor']->hasPermission('content.write'))->toBeTrue();
    $runId = t076Queue($store, t076Page($store['product_id']));
    $draftId = (string) DB::table('ai_drafts')->where('run_id', $runId)->value('id');
    $edited = t076Page($store['product_id'], 'Edited copy for the lighting studio.');
    $edited['title'] = 'Studio lighting guide';
    $applied = app(ApplyDraft::class)->apply($store['editor'], $draftId, [
        'expected_revision_id' => $store['revision_id'],
        'document' => $edited,
    ]);
    expect($applied['page_id'])->toBe($store['page_id'])
        ->and($applied['version'])->toBe(2)
        ->and((string) DB::table('ai_drafts')->where('id', $draftId)->value('status'))->toBe('applied');
    $page = DB::table('pages')->where('id', $store['page_id'])->first();
    expect((int) $page->version)->toBe(2)
        ->and((int) $page->draft_revision)->toBe(2)
        ->and($page->published_revision)->toBeNull()
        ->and((string) $page->status)->toBe('draft')
        ->and((string) $page->title)->toBe('Studio lighting guide');
    $payload = json_decode((string) DB::table('content_revisions')
        ->where('subject_id', $store['page_id'])
        ->where('revision', 2)
        ->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['title'])->toBe('Studio lighting guide')
        ->and($payload['sections'][1]['settings']['text'])->toBe('Edited copy for the lighting studio.');
    $replay = app(ApplyDraft::class)->apply($store['editor'], $draftId, [
        'expected_revision_id' => $store['revision_id'],
        'document' => $edited,
    ]);
    expect($replay['version'])->toBe(2)
        ->and((int) DB::table('pages')->where('id', $store['page_id'])->value('version'))->toBe(2);
    expect(fn () => app(PublishContent::class)->publish($store['editor'], [
        'kind' => 'page',
        'id' => $store['page_id'],
        'version' => 2,
    ]))->toThrow(AuthorizationException::class);
    $this->get('/pages/lighting-guide')->assertNotFound();
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringApply refuses a stale base revision and leaves the live pointer unchanged', function (): void {
    $store = t076Store();
    $runId = t076Queue($store, t076Page($store['product_id']));
    $draftId = (string) DB::table('ai_drafts')->where('run_id', $runId)->value('id');
    app(SaveContent::class)->savePage($store['owner'], [
        'id' => $store['page_id'],
        'version' => 1,
        'title' => 'Lighting guide rewrite',
        'slug' => 'lighting-guide',
        'status' => 'draft',
    ]);
    expect(fn () => app(ApplyDraft::class)->apply($store['editor'], $draftId, [
        'expected_revision_id' => $store['revision_id'],
        'document' => t076Page($store['product_id']),
    ]))->toThrow(AiDraftConflict::class);
    expect((string) DB::table('ai_drafts')->where('id', $draftId)->value('status'))->toBe('candidate')
        ->and((int) DB::table('pages')->where('id', $store['page_id'])->value('version'))->toBe(2)
        ->and((string) DB::table('pages')->where('id', $store['page_id'])->value('title'))->toBe('Lighting guide rewrite');
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringApply studio shows usage and a diff and lets an editor apply without a publish control', function (): void {
    $store = t076Store();
    $runId = t076Queue($store, t076Page($store['product_id']));
    $this->actingAs($store['editor'], 'staff')->withSession(catalogStaffSession($store['editor']));
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canUseAi', true)
        ->where('canConfigureAi', false));
    $studio = $this->get('/admin/ai/studio/'.$runId);
    $studio->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AiStudio')
        ->where('canPublish', false)
        ->where('canApply', true)
        ->where('stale', false)
        ->where('usage.total_tokens', 120)
        ->where('candidate.title', 'Studio lighting')
        ->where('base.title', 'Lighting guide')
        ->has('warnings', 2)
        ->where('expectedRevisionId', $store['revision_id']));
    $html = $studio->getContent();
    expect($html)->toContain('Studio lighting')
        ->and($html)->toContain('120')
        ->and($html)->not->toContain('Publish page')
        ->and($html)->not->toContain('sk-test-mrktflow-authoring-secret');
    $preview = $this->postJson('/admin/ai/studio/'.$runId.'/preview', [
        'document' => t076Page($store['product_id']),
    ]);
    $preview->assertOk();
    expect((string) $preview->json('html'))->toContain('Light your studio');
    $edited = t076Page($store['product_id'], 'Merchant-reviewed lighting copy.');
    $this->postJson('/admin/ai/studio/'.$runId.'/apply', [
        'expected_revision_id' => $store['revision_id'],
        'document' => $edited,
    ])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('published_revision', 0);
    expect((string) DB::table('pages')->where('id', $store['page_id'])->value('title'))->toBe('Studio lighting');
    $this->postJson('/admin/content/publish', [
        'kind' => 'page',
        'id' => $store['page_id'],
        'version' => 2,
    ])->assertForbidden();
    expect(DB::table('pages')->where('id', $store['page_id'])->value('published_revision'))->toBeNull();
    $this->get('/pages/lighting-guide')->assertNotFound();
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringApply HTTP keeps privileged staff behind two-factor and rejects customers', function (): void {
    $store = t076Store();
    $runId = t076Queue($store, t076Page($store['product_id']));
    $payload = [
        'expected_revision_id' => $store['revision_id'],
        'document' => t076Page($store['product_id']),
    ];
    $this->get('/admin/ai/studio/'.$runId)->assertRedirect('/admin/login');
    $this->postJson('/admin/ai/studio/'.$runId.'/apply', $payload)->assertUnauthorized();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper',
        'email' => 'shopper-apply@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1]);
    $this->getJson('/admin/ai/studio/'.$runId)->assertUnauthorized();
    $this->postJson('/admin/ai/studio/'.$runId.'/apply', $payload)->assertUnauthorized();
    auth('customer')->logout();
    $this->flushSession();
    $this->actingAs($store['owner'], 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/ai/studio/'.$runId)->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/ai/studio/'.$runId.'/apply', $payload)->assertForbidden();
    expect((string) DB::table('ai_drafts')->where('run_id', $runId)->value('status'))->toBe('candidate');
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringApply rejects invalid edited documents and forged posts', function (): void {
    $store = t076Store();
    $runId = t076Queue($store, t076Page($store['product_id']));
    $draftId = (string) DB::table('ai_drafts')->where('run_id', $runId)->value('id');
    $invalid = t076Page($store['product_id'], '{{constructor.constructor("alert(1)")()}}');
    expect(fn () => app(ApplyDraft::class)->apply($store['editor'], $draftId, [
        'expected_revision_id' => $store['revision_id'],
        'document' => $invalid,
    ]))->toThrow(ValidationException::class);
    expect((string) DB::table('ai_drafts')->where('id', $draftId)->value('status'))->toBe('candidate');
    expect(fn () => app(ApplyDraft::class)->apply($store['owner'], $draftId, [
        'expected_revision_id' => $store['revision_id'],
        'document' => t076Page($store['product_id']),
    ]))->toThrow(AuthorizationException::class);
    $this->actingAs($store['editor'], 'staff')->withSession(catalogStaffSession($store['editor']));
    $this->postJson('/admin/ai/studio/'.$runId.'/apply', [
        'expected_revision_id' => $store['revision_id'],
        'document' => t076Page($store['product_id'], '<script>alert(1)</script>'),
    ])->assertStatus(422);
    $this->app->instance('env', 'local');
    $this->postJson('/admin/ai/studio/'.$runId.'/apply', [
        'expected_revision_id' => $store['revision_id'],
        'document' => t076Page($store['product_id']),
    ])->assertStatus(419);
    $this->app->instance('env', 'testing');
    expect(fn () => app(ApplyDraft::class)->apply($store['editor'], 'not-a-uuid', [
        'expected_revision_id' => $store['revision_id'],
        'document' => t076Page($store['product_id']),
    ]))->toThrow(HttpException::class);
    $this->get('/')->assertStatus(503);
});
