<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\ThemesContent\Actions\ApplyImport;
use App\Domain\ThemesContent\Actions\PublishContent;
use App\Domain\ThemesContent\Actions\ReadPublishedContent;
use App\Domain\ThemesContent\Actions\ReadSectionDraft;
use App\Domain\ThemesContent\Actions\SaveSectionDraft;
use App\Domain\ThemesContent\Actions\ValidateImport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

afterEach(function (): void {
    foreach ($GLOBALS['t069Zips'] ?? [] as $path) {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
    $GLOBALS['t069Zips'] = [];
});

/** @param  list<string>|null  $permissions */
function t069StaffAs(StaffAccount $staff, string $role, ?array $permissions = null): StaffAccount
{
    DB::table('staff_accounts')->where('id', $staff->id)->update([
        'role' => $role,
        'permissions' => json_encode($permissions ?? StaffPermissions::forRole($role)),
    ]);

    return $staff->fresh();
}

/** @return array<string, mixed> */
function t069Hero(string $heading = 'Imported heading'): array
{
    return ['id' => 'intro', 'type' => 'hero', 'version' => 1, 'settings' => ['heading' => $heading]];
}

/** @return array<string, mixed> */
function t069ImageText(string $url = '/media/hero.png'): array
{
    return [
        'id' => 'feature',
        'type' => 'image-text',
        'version' => 1,
        'settings' => [
            'image' => ['url' => $url, 'alt' => 'A studio lamp on the bench'],
            'text' => 'A data-only imported block.',
        ],
    ];
}

/** @return array<string, mixed> */
function t069CategoryGrid(string $alias = 'ref:cameras'): array
{
    return [
        'id' => 'categories',
        'type' => 'category-grid',
        'version' => 1,
        'settings' => ['heading' => 'Shop by category'],
        'binding' => ['kind' => 'categories', 'publicIds' => [$alias]],
    ];
}

/**
 * @param  array<string, mixed>  $manifest
 * @param  array<string, string>  $files
 */
function t069Package(array $manifest, array $files = []): UploadedFile
{
    $path = sys_get_temp_dir().'/mrktflow-t069-'.Str::uuid().'.zip';
    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
    $GLOBALS['t069Zips'][] = $path;

    return new UploadedFile($path, 'template.zip', 'application/zip', null, true);
}

function t069Png(string $name = 'hero.png'): string
{
    $file = UploadedFile::fake()->image($name, 16, 12);
    $bytes = file_get_contents($file->getRealPath());
    expect($bytes)->toBeString();

    return $bytes;
}

/** @param  array<string, mixed>  $overrides */
function t069Manifest(array $overrides = []): array
{
    return array_replace([
        'schemaVersion' => '1.0',
        'kind' => 'template-package',
        'documents' => [[
            'kind' => 'section_draft',
            'page' => 'home',
            'slot' => 'content',
            'sections' => [t069Hero()],
        ]],
        'media' => [],
        'references' => ['products' => [], 'categories' => []],
    ], $overrides);
}

function t069PublishLive(StaffAccount $owner, string $heading = 'Live heading'): void
{
    app(SaveSectionDraft::class)->save($owner, 'home', 'content', 0, [t069Hero($heading)]);
    app(PublishContent::class)->publish($owner, ['kind' => 'section_draft', 'page' => 'home', 'slot' => 'content', 'version' => 1]);
}

function t069LiveHeading(): string
{
    $live = app(ReadPublishedContent::class)->sectionDraft('home', 'content');

    return (string) $live['sections'][0]['settings']['heading'];
}

it('ThemesContentImport stages a JSON and media package into drafts without publishing', function (): void {
    $owner = catalogInstalledOwner();
    t069PublishLive($owner);
    $category = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $png = t069Png();
    $report = app(ValidateImport::class)->validate($owner, [
        'file' => t069Package(t069Manifest([
            'documents' => [[
                'kind' => 'section_draft',
                'page' => 'home',
                'slot' => 'content',
                'sections' => [t069Hero('Imported heading'), t069ImageText(), t069CategoryGrid()],
            ]],
            'media' => [['id' => 'hero', 'path' => 'media/hero.png']],
        ]), ['media/hero.png' => $png]),
        'references' => ['categories' => ['cameras' => $category['id']]],
    ]);
    expect($report['can_apply'])->toBeTrue()
        ->and($report['media'])->toHaveCount(1)
        ->and($report['aliases']['categories'])->toContain('cameras');
    $applied = app(ApplyImport::class)->apply($owner, [
        'import_id' => $report['import_id'],
        'references' => ['categories' => ['cameras' => $category['id']]],
    ]);
    expect($applied['applied'])->toBeTrue()
        ->and($applied['replayed'])->toBeFalse()
        ->and(t069LiveHeading())->toBe('Live heading');
    $draft = app(ReadSectionDraft::class)->read('home', 'content');
    expect($draft['version'])->toBe(2)
        ->and($draft['publishedRevision'])->toBe(1)
        ->and($draft['sections'][0]['settings']['heading'])->toBe('Imported heading')
        ->and($draft['sections'][1]['settings']['image']['url'])->toMatch('#^/storage/media/[0-9a-f-]{36}/[a-f0-9]{64}\.png$#D')
        ->and($draft['sections'][2]['binding']['publicIds'])->toBe([$category['id']]);
    $this->get('/')->assertStatus(503)->assertDontSee('Imported heading');
});

it('ThemesContentImport rejects a malicious zip and leaves live content unchanged', function (): void {
    $owner = catalogInstalledOwner();
    t069PublishLive($owner);
    $php = t069Package(t069Manifest(), ['hooks/payload.php' => '<?php echo "owned";']);
    expect(fn () => app(ValidateImport::class)->validate($owner, ['file' => $php]))
        ->toThrow(ValidationException::class);
    $script = t069Package(t069Manifest([
        'documents' => [[
            'kind' => 'section_draft',
            'page' => 'home',
            'slot' => 'content',
            'sections' => [['id' => 'xss', 'type' => 'rich-text', 'version' => 1, 'settings' => ['text' => '<script>alert(1)</script>']]],
        ]],
    ]));
    expect(fn () => app(ValidateImport::class)->validate($owner, ['file' => $script]))
        ->toThrow(ValidationException::class);
    $vue = t069Package(t069Manifest([
        'documents' => [[
            'kind' => 'section_draft',
            'page' => 'home',
            'slot' => 'content',
            'sections' => [t069Hero('{{constructor.constructor("alert(1)")()}}')],
        ]],
    ]));
    expect(fn () => app(ValidateImport::class)->validate($owner, ['file' => $vue]))
        ->toThrow(ValidationException::class);
    $escape = t069Package(t069Manifest(), ['media/../../evil.php' => '<?php']);
    expect(fn () => app(ValidateImport::class)->validate($owner, ['file' => $escape]))
        ->toThrow(ValidationException::class);
    expect(t069LiveHeading())->toBe('Live heading')
        ->and(app(ReadSectionDraft::class)->read('home', 'content')['version'])->toBe(1)
        ->and(DB::table('content_import_files')->count())->toBe(0);
    $this->get('/')->assertStatus(503);
});

it('ThemesContentImport rejects unknown blocks without changing live content', function (): void {
    $owner = catalogInstalledOwner();
    t069PublishLive($owner);
    expect(fn () => app(ValidateImport::class)->validate($owner, [
        'file' => t069Package(t069Manifest([
            'documents' => [[
                'kind' => 'section_draft',
                'page' => 'home',
                'slot' => 'content',
                'sections' => [t069Hero(), ['id' => 'evil', 'type' => 'custom-php', 'version' => 1, 'settings' => ['code' => 'nope']]],
            ]],
        ])),
    ]))->toThrow(ValidationException::class);
    expect(t069LiveHeading())->toBe('Live heading')
        ->and(app(ReadSectionDraft::class)->read('home', 'content')['sections'][0]['settings']['heading'])->toBe('Live heading');
});

it('ThemesContentImport replays the same import without moving the live revision', function (): void {
    $owner = catalogInstalledOwner();
    t069PublishLive($owner);
    $report = app(ValidateImport::class)->validate($owner, [
        'file' => t069Package(t069Manifest()),
    ]);
    $first = app(ApplyImport::class)->apply($owner, ['import_id' => $report['import_id']]);
    $draftVersion = app(ReadSectionDraft::class)->read('home', 'content')['version'];
    $replay = app(ApplyImport::class)->apply($owner, ['import_id' => $report['import_id']]);
    expect($first['replayed'])->toBeFalse()
        ->and($replay['replayed'])->toBeTrue()
        ->and(app(ReadSectionDraft::class)->read('home', 'content')['version'])->toBe($draftVersion)
        ->and(t069LiveHeading())->toBe('Live heading')
        ->and((int) DB::table('content_drafts')->where('page_key', 'home')->where('slot', 'content')->value('published_revision'))->toBe(1);
});

it('ThemesContentImport denies guests customers fulfillment and unverified privileged staff', function (): void {
    $owner = catalogInstalledOwner();
    t069PublishLive($owner);
    $upload = t069Package(t069Manifest());

    $this->get('/admin/content/import')->assertRedirect('/admin/login');
    $this->post('/admin/content/import', ['file' => $upload])->assertRedirect('/admin/login');

    $customer = new CustomerAccount;
    $customer->forceFill([
        'name' => 'Shopper',
        'email' => 'shopper@example.test',
        'password' => Hash::make(catalogOwnerInput()['password']),
        'is_active' => true,
        'session_version' => 1,
    ])->save();
    $this->actingAs($customer, 'customer')->postJson('/admin/content/import', ['file' => $upload])->assertUnauthorized();

    $fulfillment = t069StaffAs($owner, 'fulfillment');
    $this->actingAs($fulfillment, 'staff')->withSession(catalogStaffSession($fulfillment));
    $this->getJson('/admin/content/import')->assertForbidden();
    $this->postJson('/admin/content/import', ['file' => $upload])->assertForbidden();

    $editor = t069StaffAs($owner, 'content_editor');
    $this->actingAs($editor, 'staff')->withSession(catalogStaffSession($editor));
    $this->get('/admin/content/import')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('TemplateImport')
        ->where('canWrite', true)
        ->where('preview', null));

    $owner = t069StaffAs($owner, 'owner');
    $this->actingAs($owner, 'staff')->withSession(['staff_session_version' => $owner->session_version]);
    $this->get('/admin/content/import')->assertRedirect('/admin/two-factor');
    $this->post('/admin/content/import', ['file' => t069Package(t069Manifest())])->assertRedirect('/admin/two-factor');
    expect(t069LiveHeading())->toBe('Live heading');
    expect(fn () => app(ValidateImport::class)->validate($fulfillment, ['file' => t069Package(t069Manifest())]))
        ->toThrow(AuthorizationException::class);
});

it('ThemesContentImport HTTP stages and applies a package into drafts', function (): void {
    $owner = catalogInstalledOwner();
    $this->actingAs($owner, 'staff')->withSession(catalogStaffSession($owner));
    t069PublishLive($owner);

    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('AdminHome')
        ->where('canManageContent', true));
    $this->get('/admin/content/import')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('TemplateImport')
        ->where('canWrite', true)
        ->where('preview', null));
    $this->post('/admin/content/import', ['file' => t069Package(t069Manifest([
        'documents' => [[
            'kind' => 'section_draft',
            'page' => 'home',
            'slot' => 'content',
            'sections' => [t069Hero('HTTP imported')],
        ]],
    ]))])->assertRedirect();
    $importId = (string) DB::table('content_import_files')->value('id');
    expect($importId)->not->toBe('');
    $this->get('/admin/content/import/'.$importId)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('TemplateImport')
        ->where('preview.can_apply', true)
        ->where('applied', null));
    $this->post('/admin/content/import/'.$importId.'/apply')->assertRedirect('/admin/content/import/'.$importId);
    $this->get('/admin/content/import/'.$importId)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('TemplateImport')
        ->where('applied.applied', true)
        ->where('applied.replayed', false));
    expect(app(ReadSectionDraft::class)->read('home', 'content')['sections'][0]['settings']['heading'])->toBe('HTTP imported')
        ->and(t069LiveHeading())->toBe('Live heading');
    $this->get('/')->assertStatus(503)->assertDontSee('HTTP imported');
});
