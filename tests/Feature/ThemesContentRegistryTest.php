<?php

declare(strict_types=1);

use App\Domain\Platform\Support\PlatformVersion;
use App\Domain\ThemesContent\Actions\RegisterTheme;
use App\Domain\ThemesContent\Contracts\ThemeRegistry;
use App\Domain\ThemesContent\Data\ThemeRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function themeRegistryRoot(): string
{
    $root = sys_get_temp_dir().'/mrktflow-themes-'.Str::uuid();
    File::ensureDirectoryExists($root);
    $GLOBALS['themeRegistryRoots'][] = $root;

    return $root;
}

/**
 * @param  array<string, mixed>  $manifest
 * @param  list<string>  $files
 */
function themeRegistryTheme(string $root, string $directory, array $manifest, array $files = ['views/product.blade.php', 'src/entry.ts', 'src/theme.css']): string
{
    $path = $root.'/'.$directory;
    foreach ($files as $file) {
        File::ensureDirectoryExists(dirname($path.'/'.$file));
        File::put($path.'/'.$file, 'fixture');
    }
    File::ensureDirectoryExists($path);
    File::put($path.'/theme.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

    return $path;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function themeRegistryManifest(array $overrides = []): array
{
    return array_merge([
        'schemaVersion' => '1.0',
        'id' => 'acme/shop',
        'version' => '1.0.0',
        'name' => 'Acme Shop',
        'requires' => ['core' => '>=1.0.0 <2.0.0', 'themeApi' => '1'],
        'templates' => ['product.default' => 'views/product.blade.php'],
        'sections' => ['hero', 'rich-text'],
        'assets' => ['entry' => 'src/entry.ts', 'style' => 'src/theme.css'],
    ], $overrides);
}

afterEach(function (): void {
    foreach ($GLOBALS['themeRegistryRoots'] ?? [] as $root) {
        File::deleteDirectory($root);
    }
    $GLOBALS['themeRegistryRoots'] = [];
});

it('ThemesContentRegistry evaluates bounded core and theme API ranges', function (): void {
    expect(PlatformVersion::CORE_VERSION)->toBe('1.0.0')
        ->and(PlatformVersion::THEME_API_VERSION)->toBe(1)
        ->and(PlatformVersion::satisfies('>=1.0.0 <2.0.0', '1.0.0'))->toBeTrue()
        ->and(PlatformVersion::satisfies('>=2.0.0 <3.0.0', '1.0.0'))->toBeFalse()
        ->and(PlatformVersion::satisfies('1', '1.0.0'))->toBeTrue()
        ->and(PlatformVersion::satisfies('2', '1.0.0'))->toBeFalse()
        ->and(PlatformVersion::satisfies('1.0.0', '1.0.0'))->toBeTrue()
        ->and(PlatformVersion::satisfies('>1.0.0', '1.0.0'))->toBeFalse()
        ->and(PlatformVersion::satisfies('<=1.0.0', '1.0.0'))->toBeTrue();
    expect(fn () => PlatformVersion::satisfies('banana', '1.0.0'))->toThrow(InvalidArgumentException::class);
});

it('ThemesContentRegistry registers the installed default theme as valid', function (): void {
    $registry = app(ThemeRegistry::class);
    $registration = $registry->register(base_path('themes/default'));

    expect($registration)->toBeInstanceOf(ThemeRegistration::class)
        ->and($registration->themeId)->toBe('mrktflow/default')
        ->and($registration->status)->toBe(ThemeRegistration::STATUS_VALID)
        ->and($registration->errors)->toBe([])
        ->and($registration->activatable())->toBeTrue()
        ->and($registration->checksum)->toBe(hash_file('sha256', base_path('themes/default/theme.json')))
        ->and($registry->isActivatable('mrktflow/default'))->toBeTrue()
        ->and($registry->find('mrktflow/default')?->themeId)->toBe('mrktflow/default')
        ->and(DB::table('installed_themes')->where('theme_id', 'mrktflow/default')->value('status'))->toBe('valid');
});

it('ThemesContentRegistry registers a valid fixture theme and replays idempotently', function (): void {
    $root = themeRegistryRoot();
    $registry = new RegisterTheme($root);
    $directory = themeRegistryTheme($root, 'acme', themeRegistryManifest());

    $first = $registry->register($directory);
    $second = $registry->register($directory);

    expect($first->status)->toBe(ThemeRegistration::STATUS_VALID)
        ->and($first->errors)->toBe([])
        ->and($second->status)->toBe(ThemeRegistration::STATUS_VALID)
        ->and(DB::table('installed_themes')->where('theme_id', 'acme/shop')->count())->toBe(1);
});

it('ThemesContentRegistry refuses a theme id already registered from another directory', function (): void {
    $root = themeRegistryRoot();
    $registry = new RegisterTheme($root);
    $registry->register(themeRegistryTheme($root, 'acme', themeRegistryManifest()));
    $other = themeRegistryTheme($root, 'copy', themeRegistryManifest());

    expect(fn () => $registry->register($other))->toThrow(DomainException::class);
    expect(DB::table('installed_themes')->where('theme_id', 'acme/shop')->value('path'))->toBe('acme');
});

it('ThemesContentRegistry records traversal and escaping paths as invalid', function (array $overrides, string $needle): void {
    $root = themeRegistryRoot();
    $registry = new RegisterTheme($root);
    $registration = $registry->register(themeRegistryTheme($root, 'acme', themeRegistryManifest($overrides)));

    expect($registration->status)->toBe(ThemeRegistration::STATUS_INVALID)
        ->and($registration->activatable())->toBeFalse()
        ->and($registry->isActivatable('acme/shop'))->toBeFalse()
        ->and(implode(' ', $registration->errors))->toContain($needle);
})->with([
    'dot-dot template' => [['templates' => ['product.default' => '../secret.blade.php']], 'product.default'],
    'nested traversal asset' => [['assets' => ['entry' => 'src/../../secret.ts', 'style' => 'src/theme.css']], 'entry'],
    'absolute asset' => [['assets' => ['entry' => '/etc/passwd.ts', 'style' => 'src/theme.css']], 'entry'],
    'backslash traversal' => [['templates' => ['product.default' => '..\\secret.blade.php']], 'product.default'],
    'cross-theme asset' => [['assets' => ['entry' => 'themes/other/src/entry.ts', 'style' => 'src/theme.css']], 'entry'],
    'remote asset' => [['assets' => ['entry' => 'https://evil.example/entry.ts', 'style' => 'src/theme.css']], 'entry'],
]);

it('ThemesContentRegistry records a symlink escaping the theme directory as invalid', function (): void {
    $root = themeRegistryRoot();
    File::put($root.'/outside.blade.php', 'secret');
    $directory = themeRegistryTheme($root, 'acme', themeRegistryManifest([
        'templates' => ['product.default' => 'views/escape.blade.php'],
    ]));
    symlink($root.'/outside.blade.php', $directory.'/views/escape.blade.php');

    $registration = (new RegisterTheme($root))->register($directory);

    expect($registration->status)->toBe(ThemeRegistration::STATUS_INVALID)
        ->and(implode(' ', $registration->errors))->toContain('product.default');
});

it('ThemesContentRegistry names missing templates and assets at registration', function (array $overrides, string $needle): void {
    $root = themeRegistryRoot();
    $registry = new RegisterTheme($root);
    $registration = $registry->register(themeRegistryTheme($root, 'acme', themeRegistryManifest($overrides), ['src/entry.ts', 'src/theme.css']));

    expect($registration->status)->toBe(ThemeRegistration::STATUS_INVALID)
        ->and($registration->activatable())->toBeFalse()
        ->and(implode(' ', $registration->errors))->toContain($needle);
})->with([
    'missing template' => [['templates' => ['product.default' => 'views/nope.blade.php']], 'views/nope.blade.php'],
    'missing style' => [['assets' => ['entry' => 'src/entry.ts', 'style' => 'src/missing.css']], 'src/missing.css'],
    'missing entry' => [['assets' => ['entry' => 'src/missing.ts', 'style' => 'src/theme.css']], 'src/missing.ts'],
]);

it('ThemesContentRegistry records incompatible API and core requirements as invalid', function (array $requires, string $needle): void {
    $root = themeRegistryRoot();
    $registry = new RegisterTheme($root);
    $registration = $registry->register(themeRegistryTheme($root, 'acme', themeRegistryManifest(['requires' => $requires])));

    expect($registration->status)->toBe(ThemeRegistration::STATUS_INVALID)
        ->and($registration->activatable())->toBeFalse()
        ->and($registry->isActivatable('acme/shop'))->toBeFalse()
        ->and(implode(' ', $registration->errors))->toContain($needle);
})->with([
    'future theme api' => [['core' => '>=1.0.0 <2.0.0', 'themeApi' => '2'], 'themeApi'],
    'future core' => [['core' => '>=2.0.0 <3.0.0', 'themeApi' => '1'], 'core'],
    'unparsable core' => [['core' => 'banana', 'themeApi' => '1'], 'core'],
    'missing theme api' => [['core' => '>=1.0.0 <2.0.0'], 'themeApi'],
]);

it('ThemesContentRegistry records unknown manifest fields and bad versions as invalid', function (array $overrides, string $needle): void {
    $root = themeRegistryRoot();
    $registration = (new RegisterTheme($root))->register(themeRegistryTheme($root, 'acme', themeRegistryManifest($overrides)));

    expect($registration->status)->toBe(ThemeRegistration::STATUS_INVALID)
        ->and(implode(' ', $registration->errors))->toContain($needle);
})->with([
    'unknown field' => [['evil' => true], 'evil'],
    'bad theme version' => [['version' => 'banana'], 'version'],
    'bad schema version' => [['schemaVersion' => '9.9'], 'schemaVersion'],
    'bad id' => [['id' => 'No Spaces'], 'id'],
]);

it('ThemesContentRegistry rejects structurally unusable manifests before recording', function (): void {
    $root = themeRegistryRoot();
    $registry = new RegisterTheme($root);

    $noManifest = $root.'/empty';
    File::ensureDirectoryExists($noManifest);
    expect(fn () => $registry->register($noManifest))->toThrow(InvalidArgumentException::class);

    $badJson = $root.'/badjson';
    File::ensureDirectoryExists($badJson);
    File::put($badJson.'/theme.json', '{not json');
    expect(fn () => $registry->register($badJson))->toThrow(InvalidArgumentException::class);

    expect(fn () => $registry->register($root.'/does-not-exist'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $registry->register('/etc'))->toThrow(InvalidArgumentException::class);
    expect(DB::table('installed_themes')->count())->toBe(0);
});

it('ThemesContentRegistry revalidates a fixed manifest back to valid', function (): void {
    $root = themeRegistryRoot();
    $registry = new RegisterTheme($root);
    $directory = themeRegistryTheme($root, 'acme', themeRegistryManifest([
        'assets' => ['entry' => 'src/entry.ts', 'style' => 'src/missing.css'],
    ]));
    expect($registry->register($directory)->status)->toBe(ThemeRegistration::STATUS_INVALID);

    File::put($directory.'/src/missing.css', 'fixed');
    $registration = $registry->register($directory);

    expect($registration->status)->toBe(ThemeRegistration::STATUS_VALID)
        ->and($registration->errors)->toBe([])
        ->and($registry->isActivatable('acme/shop'))->toBeTrue()
        ->and(DB::table('installed_themes')->where('theme_id', 'acme/shop')->count())->toBe(1);
});

it('ThemesContentRegistry returns null and false for unknown themes', function (): void {
    $registry = app(ThemeRegistry::class);

    expect($registry->find('nobody/nothing'))->toBeNull()
        ->and($registry->isActivatable('nobody/nothing'))->toBeFalse();
});
