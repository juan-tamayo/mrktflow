<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
});

/** Switches the given staff account to a role (or an explicit permission list) and returns it fresh. */
function t064StaffAs(StaffAccount $staff, string $role, ?array $permissions = null): StaffAccount
{
    DB::table('staff_accounts')->where('id', $staff->id)->update([
        'role' => $role,
        'permissions' => json_encode($permissions ?? StaffPermissions::forRole($role)),
    ]);

    return $staff->fresh();
}

/** A published category a binding can point at. */
function t064Category(StaffAccount $owner): string
{
    return app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']))['id'];
}

/** @return array<string, mixed> */
function t064Hero(array $settings = []): array
{
    return ['id' => 'intro', 'type' => 'hero', 'version' => 1, 'settings' => array_merge(['heading' => 'Find your camera'], $settings)];
}

it('ThemesContentEditor denies guests, customers and staff without content access', function (): void {
    $owner = catalogInstalledOwner();

    $this->get('/admin/content-editor')->assertRedirect('/admin/login');
    $this->putJson('/admin/content-editor/draft', ['page' => 'home', 'slot' => 'content', 'version' => 0, 'sections' => []])->assertUnauthorized();

    $customer = new CustomerAccount;
    $customer->forceFill(['name' => 'Shopper', 'email' => 'shopper@example.test', 'password' => Hash::make(catalogOwnerInput()['password']), 'is_active' => true, 'session_version' => 1])->save();
    $this->actingAs($customer, 'customer')->getJson('/admin/content-editor/draft?page=home&slot=content')->assertUnauthorized();

    $fulfillment = t064StaffAs($owner, 'fulfillment');
    $this->actingAs($fulfillment, 'staff')->withSession(catalogStaffSession($fulfillment));
    $this->get('/admin/content-editor')->assertForbidden();
    $this->getJson('/admin/content-editor/draft?page=home&slot=content')->assertForbidden();
    $this->postJson('/admin/content-editor/preview', ['page' => 'home', 'slot' => 'content', 'sections' => []])->assertForbidden();
    $this->putJson('/admin/content-editor/draft', ['page' => 'home', 'slot' => 'content', 'version' => 0, 'sections' => []])->assertForbidden();
});

it('ThemesContentEditor lets read-only staff view and preview but not save', function (): void {
    $owner = catalogInstalledOwner();
    $reader = t064StaffAs($owner, 'content_editor', ['admin.access', 'content.read']);
    $this->actingAs($reader, 'staff')->withSession(catalogStaffSession($reader));

    $this->get('/admin/content-editor')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('ContentEditor')
            ->where('canWrite', false)
            ->has('definitions', 12)
            ->where('initial.page', 'home')
            ->where('initial.slot', 'content'));
    $this->getJson('/admin/content-editor/draft?page=home&slot=content')->assertOk()
        ->assertJsonPath('version', 0)
        ->assertJsonPath('sections', []);
    $this->postJson('/admin/content-editor/preview', ['page' => 'home', 'slot' => 'content', 'sections' => [t064Hero()]])
        ->assertOk()
        ->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'data-section-type="hero"'));
    $this->putJson('/admin/content-editor/draft', ['page' => 'home', 'slot' => 'content', 'version' => 0, 'sections' => [t064Hero()]])
        ->assertForbidden();
    expect(DB::table('content_drafts')->count())->toBe(0);
});

it('ThemesContentEditor saves a valid draft normalized, stripped of resolved data and versioned', function (): void {
    $owner = catalogInstalledOwner();
    $categoryId = t064Category($owner);
    $editor = t064StaffAs($owner, 'content_editor');
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor));

    $grid = ['id' => 'grid', 'type' => 'product-grid', 'version' => 1, 'settings' => ['heading' => 'Shop'],
        'binding' => ['kind' => 'category', 'publicId' => $categoryId]];
    $response = $this->putJson('/admin/content-editor/draft', [
        'page' => 'home', 'slot' => 'content', 'version' => 0, 'sections' => [t064Hero(), $grid],
    ])->assertOk()->assertJsonPath('version', 1);

    $sections = $response->json('sections');
    expect($sections[0]['settings']['alignment'])->toBe('left')
        ->and($sections[1]['settings']['limit'])->toBe(8)
        ->and($sections[1]['binding'])->toBe(['kind' => 'category', 'publicId' => $categoryId]);

    $row = DB::table('content_drafts')->where('page_key', 'home')->where('slot', 'content')->first();
    expect($row)->not->toBeNull()
        ->and($row->version)->toBe(1)
        ->and($row->updated_by)->toBe($editor->id)
        ->and(str_contains($row->sections, 'resolved'))->toBeFalse();

    $this->putJson('/admin/content-editor/draft', [
        'page' => 'home', 'slot' => 'content', 'version' => 1, 'sections' => [t064Hero(['heading' => 'New heading'])],
    ])->assertOk()->assertJsonPath('version', 2);

    $this->getJson('/admin/content-editor/draft?page=home&slot=content')->assertOk()
        ->assertJsonPath('version', 2)
        ->assertJsonPath('sections.0.settings.heading', 'New heading');
});

it('ThemesContentEditor refuses a stale version without changing the draft', function (): void {
    $owner = catalogInstalledOwner();
    $editor = t064StaffAs($owner, 'content_editor');
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor));

    $this->putJson('/admin/content-editor/draft', [
        'page' => 'home', 'slot' => 'content', 'version' => 0, 'sections' => [t064Hero()],
    ])->assertOk();

    $this->putJson('/admin/content-editor/draft', [
        'page' => 'home', 'slot' => 'content', 'version' => 0, 'sections' => [t064Hero(['heading' => 'Stale write'])],
    ])->assertStatus(409)->assertJsonPath('currentVersion', 1);

    $this->getJson('/admin/content-editor/draft?page=home&slot=content')->assertOk()
        ->assertJsonPath('version', 1)
        ->assertJsonPath('sections.0.settings.heading', 'Find your camera');
});

it('ThemesContentEditor rejects an invalid payload with errors grouped per section and persists nothing', function (): void {
    $owner = catalogInstalledOwner();
    $editor = t064StaffAs($owner, 'content_editor');
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor));

    $payload = [
        t064Hero(['heading' => str_repeat('x', 201)]),
        ['id' => 'mystery', 'type' => 'nope', 'version' => 1, 'settings' => []],
        ['id' => 'copy', 'type' => 'rich-text', 'version' => 1, 'settings' => ['text' => 'One']],
        ['id' => 'copy', 'type' => 'rich-text', 'version' => 1, 'settings' => ['text' => 'Two']],
    ];
    $response = $this->putJson('/admin/content-editor/draft', [
        'page' => 'home', 'slot' => 'content', 'version' => 0, 'sections' => $payload,
    ])->assertStatus(422);

    $errors = $response->json('errors');
    expect($errors['sections']['intro'][0])->toContain("setting 'heading'")
        ->and($errors['sections']['mystery'][0])->toContain("unknown block type 'nope'")
        ->and(implode(' ', $errors['document']))->toContain("Section id 'copy' is used more than once");
    expect(DB::table('content_drafts')->count())->toBe(0);

    // The corrected payload saves cleanly — validation recovery at the API level.
    $this->putJson('/admin/content-editor/draft', [
        'page' => 'home', 'slot' => 'content', 'version' => 0, 'sections' => [t064Hero()],
    ])->assertOk()->assertJsonPath('version', 1);
});

it('ThemesContentEditor refuses unknown pages and non-section slots', function (): void {
    $owner = catalogInstalledOwner();
    $editor = t064StaffAs($owner, 'content_editor');
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor));

    foreach ([['nope', 'content'], ['home', 'head'], ['home', 'masthead'], ['home', 'footer'], ['checkout', 'checkout.actions'], ['checkout', 'checkout.summary']] as [$page, $slot]) {
        $this->putJson('/admin/content-editor/draft', [
            'page' => $page, 'slot' => $slot, 'version' => 0, 'sections' => [],
        ])->assertStatus(422);
        $this->postJson('/admin/content-editor/preview', ['page' => $page, 'slot' => $slot, 'sections' => []])->assertStatus(422);
        $this->getJson("/admin/content-editor/draft?page={$page}&slot={$slot}")->assertStatus(422);
    }
    expect(DB::table('content_drafts')->count())->toBe(0);
});

it('ThemesContentEditor previews the real rendered draft and persists nothing', function (): void {
    $owner = catalogInstalledOwner();
    $editor = t064StaffAs($owner, 'content_editor');
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor));

    $this->postJson('/admin/content-editor/preview', [
        'page' => 'home', 'slot' => 'announcement', 'sections' => [t064Hero()],
    ])->assertOk()->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'data-section-type="hero"')
        && str_contains($html, 'Find your camera')
        && str_contains($html, 'data-section-id="intro"'));

    $this->postJson('/admin/content-editor/preview', [
        'page' => 'home', 'slot' => 'content', 'sections' => [t064Hero(['heading' => str_repeat('x', 201)])],
    ])->assertStatus(422)->assertJsonPath('errors.sections.intro.0', fn (string $error): bool => str_contains($error, "setting 'heading'"));

    expect(DB::table('content_drafts')->count())->toBe(0);
});

it('ThemesContentEditor keeps drafts isolated per page and slot', function (): void {
    $owner = catalogInstalledOwner();
    $editor = t064StaffAs($owner, 'content_editor');
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor));

    $text = fn (string $id, string $body): array => ['id' => $id, 'type' => 'rich-text', 'version' => 1, 'settings' => ['text' => $body]];
    $this->putJson('/admin/content-editor/draft', ['page' => 'home', 'slot' => 'content', 'version' => 0, 'sections' => [$text('a', 'Home body')]])->assertOk();
    $this->putJson('/admin/content-editor/draft', ['page' => 'home', 'slot' => 'announcement', 'version' => 0, 'sections' => [$text('b', 'Banner')]])->assertOk();
    $this->putJson('/admin/content-editor/draft', ['page' => 'cart', 'slot' => 'content', 'version' => 0, 'sections' => [$text('c', 'Cart note')]])->assertOk();

    $this->getJson('/admin/content-editor/draft?page=home&slot=content')->assertJsonPath('sections.0.settings.text', 'Home body');
    $this->getJson('/admin/content-editor/draft?page=home&slot=announcement')->assertJsonPath('sections.0.settings.text', 'Banner');
    $this->getJson('/admin/content-editor/draft?page=cart&slot=content')->assertJsonPath('sections.0.settings.text', 'Cart note');
    $this->getJson('/admin/content-editor/draft?page=search&slot=content')->assertOk()->assertJsonPath('version', 0)->assertJsonPath('sections', []);
});
