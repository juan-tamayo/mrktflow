<?php

declare(strict_types=1);

use App\Domain\AiAuthoring\Actions\ReadAiSettings;
use App\Domain\AiAuthoring\Actions\ReserveUsage;
use App\Domain\AiAuthoring\Actions\SaveAiSettings;
use App\Domain\AiAuthoring\Data\UsageHold;
use App\Domain\AiAuthoring\Exceptions\AiAuthoringDisabled;
use App\Domain\AiAuthoring\Exceptions\AiBudgetExhausted;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\ThemesContent\Actions\SaveContent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->withoutVite();
});

/** @param  array<string, mixed>  $overrides */
function aiSettingsInput(array $overrides = []): array
{
    return array_replace([
        'enabled' => true,
        'provider' => 'openai',
        'model' => 'gpt-4o-mini-2024-07-18',
        'api_key' => 'sk-test-mrktflow-authoring-secret',
        'daily_token_budget' => 2000,
        'monthly_token_budget' => 5000,
        'author_ids' => [],
        'version' => 1,
    ], $overrides);
}

function aiEditor(StaffAccount $owner): StaffAccount
{
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
    expect($editor->hasPermission('ai.use'))->toBeTrue();
    expect($owner->role)->toBe('owner');

    return $editor;
}

it('AiAuthoringBudget lets the owner configure a secret and authors without exposing the key', function (): void {
    $owner = catalogInstalledOwner();
    $editor = aiEditor($owner);
    $saved = app(SaveAiSettings::class)->save($owner, aiSettingsInput([
        'author_ids' => [$owner->id, $editor->id],
    ]));
    expect($saved['enabled'])->toBeTrue()
        ->and($saved['has_secret'])->toBeTrue()
        ->and($saved['secret_hint'])->toBe('cret')
        ->and($saved['author_ids'])->toEqualCanonicalizing([$owner->id, $editor->id]);
    $row = DB::table('ai_settings')->where('id', 1)->first();
    expect($row)->not->toBeNull()
        ->and((string) $row->secret_payload)->not->toContain('sk-test-mrktflow-authoring-secret')
        ->and((string) Crypt::decryptString((string) $row->secret_payload))->toContain('sk-test-mrktflow-authoring-secret');
    $read = app(ReadAiSettings::class)->forOwner();
    expect(json_encode($read, JSON_THROW_ON_ERROR))->not->toContain('sk-test-mrktflow-authoring-secret')
        ->and($read['has_secret'])->toBeTrue();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/ai')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AiSettings')
        ->where('settings.has_secret', true)
        ->where('settings.secret_hint', 'cret')
        ->missing('settings.api_key')
        ->has('authors'));
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringBudget refuses non-owner secret changes and unauthorized authors', function (): void {
    $owner = catalogInstalledOwner();
    $editor = aiEditor($owner);
    $fulfillment = new StaffAccount;
    $fulfillment->forceFill([
        'name' => 'Fulfillment',
        'email' => 'ship@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'role' => 'fulfillment',
        'permissions' => StaffPermissions::forRole('fulfillment'),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    expect(fn () => app(SaveAiSettings::class)->save($editor, aiSettingsInput()))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveAiSettings::class)->save($owner, aiSettingsInput([
        'author_ids' => [$fulfillment->id],
    ])))->toThrow(ValidationException::class);
    $manager = $owner->fresh();
    DB::table('staff_accounts')->where('id', $owner->id)->update([
        'role' => 'manager',
        'permissions' => json_encode(array_values(array_unique([...StaffPermissions::forRole('manager'), 'integrations.manage']))),
    ]);
    expect(fn () => app(SaveAiSettings::class)->save($manager->fresh(), aiSettingsInput()))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('ai_settings')->where('id', 1)->value('enabled'))->toBe(0);
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringBudget keeps manual authoring usable while AI is disabled and refuses reservations', function (): void {
    $owner = catalogInstalledOwner();
    expect(app(ReadAiSettings::class)->forOwner()['enabled'])->toBeFalse();
    $page = app(SaveContent::class)->savePage($owner, [
        'title' => 'About the studio',
        'slug' => 'about',
        'status' => 'draft',
    ]);
    expect($page['path'])->toBe('pages/about');
    expect(fn () => app(ReserveUsage::class)->reserve($owner, new UsageHold(
        (string) Str::uuid(),
        (string) Str::uuid(),
        $owner->id,
        500,
        now()->addMinutes(30)->toDateTimeImmutable(),
    )))->toThrow(AiAuthoringDisabled::class);
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin/content')->assertOk();
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringBudget reserves estimated usage for authorized authors and records known or pending usage', function (): void {
    $owner = catalogInstalledOwner();
    $editor = aiEditor($owner);
    app(SaveAiSettings::class)->save($owner, aiSettingsInput([
        'daily_token_budget' => 1000,
        'monthly_token_budget' => 1000,
        'author_ids' => [$editor->id],
    ]));
    $holder = app(ReserveUsage::class);
    expect(fn () => $holder->reserve($owner, new UsageHold(
        (string) Str::uuid(),
        (string) Str::uuid(),
        $owner->id,
        400,
        now()->addMinutes(30)->toDateTimeImmutable(),
    )))->toThrow(AuthorizationException::class);
    $intent = (string) Str::uuid();
    $key = (string) Str::uuid();
    $held = $holder->reserve($editor, new UsageHold(
        $intent,
        $key,
        $editor->id,
        400,
        now()->addMinutes(30)->toDateTimeImmutable(),
    ));
    $replay = $holder->reserve($editor, new UsageHold(
        $intent,
        $key,
        $editor->id,
        400,
        now()->addMinutes(30)->toDateTimeImmutable(),
    ));
    expect($replay->reservationId)->toBe($held->reservationId)->and($held->status)->toBe('reserved');
    $unknownIntent = (string) Str::uuid();
    $unknown = $holder->reserve($editor, new UsageHold(
        $unknownIntent,
        (string) Str::uuid(),
        $editor->id,
        400,
        now()->addMinutes(30)->toDateTimeImmutable(),
    ));
    $holder->record($editor, $unknownIntent, (string) Str::uuid(), null, null);
    expect(DB::table('ai_usage_reservations')->where('id', $unknown->reservationId)->value('status'))->toBe('pending_unknown');
    expect(fn () => $holder->reserve($editor, new UsageHold(
        (string) Str::uuid(),
        (string) Str::uuid(),
        $editor->id,
        300,
        now()->addMinutes(30)->toDateTimeImmutable(),
    )))->toThrow(AiBudgetExhausted::class);
    $holder->record($editor, $intent, (string) Str::uuid(), 50, 50);
    expect((int) DB::table('ai_usage_entries')->where('intent_id', $intent)->value('total_tokens'))->toBe(100);
    $holder->reserve($editor, new UsageHold(
        (string) Str::uuid(),
        (string) Str::uuid(),
        $editor->id,
        500,
        now()->addMinutes(30)->toDateTimeImmutable(),
    ));
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringBudget serializes parallel last-budget holds across real MySQL connections', function (): void {
    $owner = catalogInstalledOwner();
    $editor = aiEditor($owner);
    app(SaveAiSettings::class)->save($owner, aiSettingsInput([
        'daily_token_budget' => 1000,
        'monthly_token_budget' => 1000,
        'author_ids' => [$editor->id],
    ]));
    $processes = [];
    $readyFiles = [];
    $start = function () use ($editor, &$processes, &$readyFiles): Process {
        $id = (string) Str::uuid();
        $ready = base_path('.runtime/ai-budget-'.$id.'.ready');
        $readyFiles[] = $ready;
        $process = new Process(
            [base_path('bin/php'), base_path('tests/Fixtures/ai-budget-race.php'), $id, '--env=testing'],
            base_path(),
            ['APP_ENV' => 'testing'],
        );
        $process->setInput(json_encode([
            'staff_id' => $editor->id,
            'intent_id' => (string) Str::uuid(),
            'operation_key' => (string) Str::uuid(),
            'estimated_tokens' => 1000,
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(15)->start();
        $processes[] = $process;

        return $process;
    };
    try {
        DB::beginTransaction();
        DB::table('ai_settings')->where('id', 1)->lockForUpdate()->first();
        $start();
        $start();
        $deadline = microtime(true) + 8;
        while ((! is_file($readyFiles[0]) || ! is_file($readyFiles[1])) && microtime(true) < $deadline) {
            expect($processes[0]->isRunning() && $processes[1]->isRunning())->toBeTrue();
            usleep(20000);
        }
        expect(is_file($readyFiles[0]) && is_file($readyFiles[1]))->toBeTrue();
        DB::commit();
        $statuses = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0);
            $statuses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
        }
        sort($statuses);
        expect($statuses)->toBe(['exhausted', 'reserved'])
            ->and(DB::table('ai_usage_reservations')->where('status', 'reserved')->count())->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        foreach ($readyFiles as $ready) {
            if (is_file($ready)) {
                unlink($ready);
            }
        }
    }
});

it('AiAuthoringBudget HTTP keeps privileged staff behind two-factor and rejects customers', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/ai')->assertRedirect('/admin/two-factor');
    $this->postJson('/admin/ai', ['settings' => aiSettingsInput()])->assertForbidden();
    auth('staff')->logout();
    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper', 'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])
        ->postJson('/admin/ai', ['settings' => aiSettingsInput()])
        ->assertUnauthorized();
    auth('customer')->logout();
    auth('staff')->logout();
    $this->flushSession();
    $editor = aiEditor($owner);
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor));
    $this->postJson('/admin/ai', ['settings' => aiSettingsInput()])->assertForbidden();
    $this->get('/')->assertStatus(503);
});

it('AiAuthoringBudget HTTP lets the owner save settings and rejects forged posts', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canConfigureAi', true));
    $this->post('/admin/ai', ['settings' => aiSettingsInput(['author_ids' => [$owner->id]])])->assertRedirect('/admin/ai');
    expect((int) DB::table('ai_settings')->where('id', 1)->value('enabled'))->toBe(1);
    $this->get('/admin/ai')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AiSettings')
        ->where('settings.enabled', true)
        ->where('settings.has_secret', true)
        ->missing('settings.api_key'));
    $this->from('/admin/ai')->post('/admin/ai', ['settings' => aiSettingsInput(['enabled' => true, 'api_key' => '', 'version' => 99])])
        ->assertRedirect('/admin/ai')->assertSessionHasErrors();
    $this->app->instance('env', 'local');
    $this->postJson('/admin/ai', ['settings' => aiSettingsInput()])->assertStatus(419);
    $this->app->instance('env', 'testing');
    expect(fn () => app(SaveAiSettings::class)->save($owner, aiSettingsInput(['version' => 1])))
        ->toThrow(HttpException::class);
    $this->get('/')->assertStatus(503);
});
