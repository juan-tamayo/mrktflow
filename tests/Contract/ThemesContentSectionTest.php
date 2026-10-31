<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Storefront\Exceptions\SlotCompositionRefused;
use App\Domain\ThemesContent\Actions\RegisterTheme;
use App\Domain\ThemesContent\Actions\RenderSections;
use App\Domain\ThemesContent\Actions\ValidateSections;
use App\Domain\ThemesContent\Exceptions\SectionValidationFailed;
use App\Domain\ThemesContent\Support\SectionRegistry;
use Illuminate\Support\Str;
use Tests\Support\RestoresMigratedSingletons;
use Tests\TestCase;

uses(TestCase::class, RestoresMigratedSingletons::class);

/**
 * Seeds an installed store with two published products in one published
 * category, plus one draft product and one draft category that bindings
 * must refuse.
 *
 * @return array{product_id: string, second_product_id: string, category_id: string, draft_product_id: string, draft_category_id: string}
 */
function t063Catalog(): array
{
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $category = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $product = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published',
        'category_ids' => [$category['id']],
    ]));
    $second = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'title' => 'Desk Lamp',
        'slug' => 'desk-lamp',
        'sku' => 'LAMP-002',
        'status' => 'published',
        'category_ids' => [$category['id']],
    ]));
    $draftProduct = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'title' => 'Unreleased Lamp',
        'slug' => 'unreleased-lamp',
        'sku' => 'LAMP-003',
    ]));
    $draftCategory = app(SaveCategory::class)->save($owner, catalogCategoryInput([
        'title' => 'Draft Category',
        'slug' => 'draft-category',
    ]));

    return [
        'product_id' => $product['id'],
        'second_product_id' => $second['id'],
        'category_id' => $category['id'],
        'draft_product_id' => $draftProduct['id'],
        'draft_category_id' => $draftCategory['id'],
    ];
}

/**
 * One valid sample instance per built-in block type (product-grid appears
 * in both binding forms). Keys are the block types the sample proves.
 *
 * @param  array{product_id: string, second_product_id: string, category_id: string}  $ids
 * @return array<string, array<string, mixed>>
 */
function t063Samples(array $ids): array
{
    return [
        'hero' => ['id' => 'intro', 'type' => 'hero', 'version' => 1, 'settings' => [
            'heading' => 'Find your camera', 'subheading' => 'Cameras for every studio.', 'alignment' => 'center',
            'cta' => ['label' => 'Shop cameras', 'path' => '/categories/cameras'],
        ]],
        'rich-text' => ['id' => 'copy', 'type' => 'rich-text', 'version' => 1, 'settings' => [
            'text' => 'Plain words only, no markup.',
        ]],
        'image-text' => ['id' => 'story', 'type' => 'image-text', 'version' => 1, 'settings' => [
            'image' => ['url' => '/media/lamp.jpg', 'alt' => 'A lamp on a desk'],
            'heading' => 'Light, considered', 'text' => 'How this lamp was made.', 'imagePosition' => 'right',
        ]],
        'gallery' => ['id' => 'shots', 'type' => 'gallery', 'version' => 1, 'settings' => [
            'heading' => 'In the studio',
            'images' => [
                ['url' => '/media/a.jpg', 'alt' => 'First view'],
                ['url' => '/media/b.jpg', 'alt' => 'Second view'],
            ],
        ]],
        'category-grid' => ['id' => 'browse', 'type' => 'category-grid', 'version' => 1, 'settings' => [
            'heading' => 'Browse the shelves',
        ], 'binding' => ['kind' => 'categories', 'publicIds' => [$ids['category_id']]]],
        'product-grid' => ['id' => 'products', 'type' => 'product-grid', 'version' => 1, 'settings' => [
            'heading' => 'Explore the collection', 'limit' => 4,
        ], 'binding' => ['kind' => 'category', 'publicId' => $ids['category_id']]],
        'featured-product' => ['id' => 'featured', 'type' => 'featured-product', 'version' => 1, 'settings' => [
            'heading' => 'Featured this week',
        ], 'binding' => ['kind' => 'product', 'publicId' => $ids['product_id']]],
        'specifications' => ['id' => 'specs', 'type' => 'specifications', 'version' => 1, 'settings' => [
            'heading' => 'Specifications',
        ], 'binding' => ['kind' => 'product', 'publicId' => $ids['product_id']]],
        'comparison-link' => ['id' => 'compare', 'type' => 'comparison-link', 'version' => 1, 'settings' => [
            'label' => 'Compare these lamps',
        ], 'binding' => ['kind' => 'products', 'publicIds' => [$ids['product_id'], $ids['second_product_id']]]],
        'faq' => ['id' => 'questions', 'type' => 'faq', 'version' => 1, 'settings' => [
            'heading' => 'Questions',
            'items' => [['question' => 'How long is delivery?', 'answer' => 'Two working days.']],
        ]],
        'call-to-action' => ['id' => 'cta', 'type' => 'call-to-action', 'version' => 1, 'settings' => [
            'heading' => 'Ready when you are', 'text' => 'Start with the lighting shelf.',
            'cta' => ['label' => 'Browse lighting', 'path' => '/categories/lighting'],
        ]],
        'article-list' => ['id' => 'guides', 'type' => 'article-list', 'version' => 1, 'settings' => [
            'heading' => 'Guides', 'limit' => 3,
        ]],
    ];
}

it('ThemesContentSection registry declares the twelve built-in blocks with typed schemas', function (): void {
    $registry = app(SectionRegistry::class);
    $types = array_keys($registry->definitions());
    sort($types);
    expect($types)->toBe([
        'article-list', 'call-to-action', 'category-grid', 'comparison-link', 'faq', 'featured-product',
        'gallery', 'hero', 'image-text', 'product-grid', 'rich-text', 'specifications',
    ]);
    foreach ($registry->definitions() as $definition) {
        expect($definition->version)->toBe(1)
            ->and($definition->label)->not->toBe('')
            ->and($definition->maxPerPage)->toBeGreaterThan(0);
    }
    $hero = $registry->definition('hero');
    $heading = $hero?->field('heading');
    expect($heading['type'] ?? null)->toBe('text')
        ->and($heading['required'] ?? null)->toBeTrue()
        ->and($heading['maxLength'] ?? null)->toBe(200);
    expect($registry->definition('not-a-block'))->toBeNull();
});

it('ThemesContentSection validates a full document of every built-in sample', function (): void {
    $ids = t063Catalog();
    $validated = app(ValidateSections::class)->validate(array_values(t063Samples($ids)));
    expect($validated)->toHaveCount(12);
    $byId = [];
    foreach ($validated as $section) {
        $byId[$section['id']] = $section;
    }
    // Defaults are applied to omitted optional settings.
    expect($byId['browse']['settings'])->not->toHaveKey('limit');
    expect($byId['guides']['settings']['limit'])->toBe(3);
    // Bindings resolve through the catalog read model to public summaries.
    expect($byId['featured']['binding']['resolved']['title'])->toBe('Studio Lamp')
        ->and($byId['featured']['binding']['resolved']['url'])->toBe('/products/studio-lamp')
        ->and($byId['featured']['binding']['resolved']['money']['formatted'])->toContain('24.99');
    expect($byId['products']['binding']['resolved']['title'])->toBe('Lighting');
    $gridTitles = array_column($byId['products']['binding']['resolved']['products'], 'title');
    expect($gridTitles)->toContain('Studio Lamp', 'Desk Lamp');
    expect($byId['browse']['binding']['resolved'][0]['url'])->toBe('/categories/lighting');
    expect($byId['specs']['binding']['resolved']['attributes'])->toBeArray();
    expect($byId['compare']['binding']['resolved'])->toHaveCount(2);
});

it('ThemesContentSection applies declared defaults when optional settings are omitted', function (): void {
    $validated = app(ValidateSections::class)->validate([
        ['id' => 'intro', 'type' => 'hero', 'version' => 1, 'settings' => ['heading' => 'Hello']],
        ['id' => 'grid', 'type' => 'product-grid', 'version' => 1, 'settings' => [], 'binding' => ['kind' => 'products', 'publicIds' => [t063Catalog()['product_id']]]],
    ]);
    expect($validated[0]['settings']['alignment'])->toBe('left')
        ->and($validated[1]['settings']['limit'])->toBe(8);
});

it('ThemesContentSection rejects unknown blocks fields and versions with useful errors', function (Closure $mutate, string $needle): void {
    $ids = t063Catalog();
    $section = ['id' => 'intro', 'type' => 'hero', 'version' => 1, 'settings' => ['heading' => 'Hello']];
    $mutate($section);
    try {
        app(ValidateSections::class)->validate([$section]);
        $this->fail('Section validation should have failed.');
    } catch (SectionValidationFailed $exception) {
        expect(implode("\n", $exception->errors()))->toContain($needle);
    }
})->with([
    'unknown block type' => [fn (array &$s) => $s['type'] = 'mega-menu', 'mega-menu'],
    'unknown settings field' => [fn (array &$s) => $s['settings']['background'] = 'red', 'background'],
    'unknown instance field' => [fn (array &$s) => $s['onclick'] = 'run()', 'onclick'],
    'wrong version' => [fn (array &$s) => $s['version'] = 2, 'version'],
    'duplicate identity field missing' => [fn (array &$s) => $s['id'] = 'Bad ID', 'id'],
    'binding on a block without one' => [fn (array &$s) => $s['binding'] = ['kind' => 'product', 'publicId' => 'x'], 'binding'],
]);

it('ThemesContentSection rejects constraint violations naming the section field and problem', function (Closure $mutate, string $needle): void {
    $section = ['id' => 'grid', 'type' => 'product-grid', 'version' => 1, 'settings' => ['heading' => 'Explore', 'limit' => 4],
        'binding' => ['kind' => 'products', 'publicIds' => [t063Catalog()['product_id']]]];
    $mutate($section);
    try {
        app(ValidateSections::class)->validate([$section]);
        $this->fail('Section validation should have failed.');
    } catch (SectionValidationFailed $exception) {
        $errors = implode("\n", $exception->errors());
        expect($errors)->toContain('grid', $needle);
    }
})->with([
    'limit below range' => [fn (array &$s) => $s['settings']['limit'] = 0, 'limit'],
    'limit above range' => [fn (array &$s) => $s['settings']['limit'] = 25, 'limit'],
    'limit wrong type' => [fn (array &$s) => $s['settings']['limit'] = 'four', 'limit'],
    'heading too long' => [fn (array &$s) => $s['settings']['heading'] = str_repeat('a', 201), 'heading'],
    'heading with markup' => [fn (array &$s) => $s['settings']['heading'] = 'Sale <b>now</b>', 'heading'],
]);

it('ThemesContentSection rejects missing required settings and unsafe paths', function (): void {
    $ids = t063Catalog();
    $cases = [
        [['id' => 'intro', 'type' => 'hero', 'version' => 1, 'settings' => []], 'heading'],
        [['id' => 'cta', 'type' => 'call-to-action', 'version' => 1, 'settings' => [
            'heading' => 'Go', 'cta' => ['label' => 'Go', 'path' => 'https://evil.example/x'],
        ]], 'path'],
        [['id' => 'cta', 'type' => 'call-to-action', 'version' => 1, 'settings' => [
            'heading' => 'Go', 'cta' => ['label' => 'Go', 'path' => '/categories/../admin'],
        ]], 'path'],
        [['id' => 'story', 'type' => 'image-text', 'version' => 1, 'settings' => [
            'image' => ['url' => '//evil.example/x.jpg', 'alt' => 'x'], 'text' => 'Words.',
        ]], 'url'],
        [['id' => 'grid', 'type' => 'product-grid', 'version' => 1, 'settings' => []], 'binding'],
        [['id' => 'grid', 'type' => 'product-grid', 'version' => 1, 'settings' => [],
            'binding' => ['kind' => 'product', 'publicId' => $ids['product_id']]], 'kind'],
    ];
    foreach ($cases as [$section, $needle]) {
        try {
            app(ValidateSections::class)->validate([$section]);
            $this->fail('Section validation should have failed for '.json_encode($section));
        } catch (SectionValidationFailed $exception) {
            expect(implode("\n", $exception->errors()))->toContain($needle);
        }
    }
});

it('ThemesContentSection refuses bindings to draft or nonexistent catalog entries without distinguishing them', function (): void {
    $ids = t063Catalog();
    $messages = [];
    foreach ([$ids['draft_product_id'], (string) Str::uuid()] as $reference) {
        try {
            app(ValidateSections::class)->validate([[
                'id' => 'featured', 'type' => 'featured-product', 'version' => 1, 'settings' => [],
                'binding' => ['kind' => 'product', 'publicId' => $reference],
            ]]);
            $this->fail('Binding validation should have failed.');
        } catch (SectionValidationFailed $exception) {
            $messages[] = implode("\n", $exception->errors());
        }
    }
    expect($messages[0])->toContain('does not exist or is not published')
        ->and($messages[1])->toContain('does not exist or is not published');
    try {
        app(ValidateSections::class)->validate([[
            'id' => 'grid', 'type' => 'product-grid', 'version' => 1, 'settings' => [],
            'binding' => ['kind' => 'category', 'publicId' => $ids['draft_category_id']],
        ]]);
        $this->fail('Binding validation should have failed.');
    } catch (SectionValidationFailed $exception) {
        expect(implode("\n", $exception->errors()))->toContain('does not exist or is not published');
    }
});

it('ThemesContentSection rejects duplicate sections references and per-page limit overruns', function (): void {
    $ids = t063Catalog();
    $hero = ['id' => 'intro', 'type' => 'hero', 'version' => 1, 'settings' => ['heading' => 'Hello']];
    $cases = [
        [[$hero, $hero], 'intro'],
        [array_map(fn (int $i): array => ['id' => 'hero-'.$i, 'type' => 'hero', 'version' => 1, 'settings' => ['heading' => 'Hi']], range(1, 4)), 'hero'],
        [[['id' => 'grid', 'type' => 'product-grid', 'version' => 1, 'settings' => [],
            'binding' => ['kind' => 'products', 'publicIds' => [$ids['product_id'], $ids['product_id']]]]], 'publicIds'],
    ];
    foreach ($cases as [$sections, $needle]) {
        try {
            app(ValidateSections::class)->validate($sections);
            $this->fail('Section validation should have failed.');
        } catch (SectionValidationFailed $exception) {
            expect(implode("\n", $exception->errors()))->toContain($needle);
        }
    }
});

it('ThemesContentSection renders only validated data through the sections view', function (): void {
    $ids = t063Catalog();
    $html = app(RenderSections::class)->render('home', 'content', [
        ['id' => 'intro', 'type' => 'hero', 'version' => 1, 'settings' => ['heading' => 'Lamps & Lights']],
        ['id' => 'products', 'type' => 'product-grid', 'version' => 1, 'settings' => ['heading' => 'Explore', 'limit' => 4],
            'binding' => ['kind' => 'category', 'publicId' => $ids['category_id']]],
    ])->render();
    expect($html)->toContain('data-section-type="hero"', 'Lamps &amp; Lights', 'data-section-type="product-grid"', 'Studio Lamp', '24.99');
});

it('ThemesContentSection never renders an invalid payload', function (): void {
    expect(fn () => app(RenderSections::class)->render('home', 'content', [
        ['id' => 'intro', 'type' => 'hero', 'version' => 1, 'settings' => ['heading' => 'Hi', 'background' => 'red']],
    ]))->toThrow(SectionValidationFailed::class);
});

it('ThemesContentSection composes into theme slots and refuses platform or unknown slots', function (): void {
    $sections = [['id' => 'intro', 'type' => 'hero', 'version' => 1, 'settings' => ['heading' => 'Hello']]];
    $view = app(RenderSections::class)->render('home', 'announcement', $sections);
    expect($view->getData()['composedPage']->viewFor('announcement'))->toBe('default-theme::sections');
    expect(fn () => app(RenderSections::class)->render('checkout', 'checkout.actions', $sections))
        ->toThrow(SlotCompositionRefused::class);
    expect(fn () => app(RenderSections::class)->render('home', 'banner', $sections))
        ->toThrow(SlotCompositionRefused::class);
});

it('ThemesContentSection default theme manifest declares the registered section types', function (): void {
    $manifest = json_decode((string) file_get_contents(base_path('themes/default/theme.json')), true, flags: JSON_THROW_ON_ERROR);
    $types = array_keys(app(SectionRegistry::class)->definitions());
    sort($types);
    $declared = $manifest['sections'] ?? [];
    sort($declared);
    expect($declared)->toBe($types);
    $registration = app(RegisterTheme::class)->register(base_path('themes/default'));
    expect($registration->status)->toBe('valid');
});
