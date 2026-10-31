<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveProductSchema;
use App\Domain\Catalog\Actions\SaveVariants;
use App\Domain\ShoppingTools\Actions\CompareProducts;
use App\Domain\ShoppingTools\Data\ComparisonView;
use App\Domain\ShoppingTools\Support\UnitConversions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

/**
 * @return array{owner: mixed, camera: string, camera_pro: string, notebook: string, cam_a1: string, cam_a1_variant: string, cam_a2: string, cam_a2_variant: string, cam_b1: string, cam_b1_variant: string, note: string, note_variant: string, draft: string, draft_variant: string}
 */
function compareCatalog(): array
{
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $camera = app(SaveProductSchema::class)->save($owner, catalogSchemaInput([
        'key' => 'camera',
        'label' => 'Camera',
        'comparison_group' => 'cameras',
        'attributes' => [
            ['key' => 'sensor.megapixels', 'type' => 'decimal', 'unit' => 'MP', 'required' => true, 'comparable' => true, 'filterable' => true, 'label' => 'Megapixels'],
            ['key' => 'body.weight', 'type' => 'integer', 'unit' => 'g', 'required' => false, 'comparable' => true, 'filterable' => false, 'label' => 'Weight'],
            ['key' => 'item.mass', 'type' => 'decimal', 'unit' => 'kg', 'required' => false, 'comparable' => true, 'filterable' => false, 'label' => 'Mass'],
            ['key' => 'lens.mount', 'type' => 'enum', 'required' => true, 'comparable' => true, 'filterable' => true, 'label' => 'Lens mount', 'options' => ['rf', 'e', 'z']],
            ['key' => 'body.stabilized', 'type' => 'boolean', 'required' => false, 'comparable' => true, 'filterable' => true, 'label' => 'Stabilized'],
            ['key' => 'care.warranty', 'type' => 'string', 'required' => false, 'comparable' => false, 'filterable' => false, 'label' => 'Warranty'],
        ],
    ]));
    $cameraPro = app(SaveProductSchema::class)->save($owner, catalogSchemaInput([
        'key' => 'camera-pro',
        'label' => 'Camera Pro',
        'comparison_group' => 'cameras',
        'attributes' => [
            ['key' => 'sensor.megapixels', 'type' => 'decimal', 'unit' => 'MP', 'required' => true, 'comparable' => true, 'filterable' => true, 'label' => 'Megapixels'],
            ['key' => 'body.weight', 'type' => 'integer', 'unit' => 'g', 'required' => false, 'comparable' => true, 'filterable' => false, 'label' => 'Body weight'],
            ['key' => 'sensor.size', 'type' => 'string', 'required' => false, 'comparable' => true, 'filterable' => false, 'label' => 'Sensor size'],
        ],
    ]));
    $notebook = app(SaveProductSchema::class)->save($owner, catalogSchemaInput([
        'key' => 'notebook',
        'label' => 'Notebook',
        'comparison_group' => 'stationery',
        'attributes' => [
            ['key' => 'page.count', 'type' => 'integer', 'unit' => 'pp', 'required' => true, 'comparable' => true, 'filterable' => true, 'label' => 'Pages'],
        ],
    ]));
    $make = function (array $overrides) use ($owner, $image): array {
        $saved = app(SaveProduct::class)->save($owner, catalogProductInput($image, $overrides));

        return ['id' => $saved['id'], 'variant' => (string) DB::table('product_variants')->where('product_id', $saved['id'])->value('id')];
    };
    $camA1 = $make([
        'title' => 'Alpha Camera', 'slug' => 'alpha-camera', 'sku' => 'CAM-A1', 'status' => 'published',
        'schema_id' => $camera['id'],
        'attributes' => [
            'sensor.megapixels' => '24.2', 'body.weight' => '1500', 'item.mass' => '1.5',
            'lens.mount' => 'rf', 'body.stabilized' => true, 'care.warranty' => '2 years',
        ],
    ]);
    $camA2 = $make([
        'title' => 'Beta Camera', 'slug' => 'beta-camera', 'sku' => 'CAM-A2', 'status' => 'published', 'price_amount' => 2599,
        'schema_id' => $camera['id'],
        'attributes' => [
            'sensor.megapixels' => '24.20', 'item.mass' => '1.50', 'lens.mount' => 'e',
        ],
    ]);
    $camB1 = $make([
        'title' => 'Pro Camera', 'slug' => 'pro-camera', 'sku' => 'CAM-B1', 'status' => 'published', 'price_amount' => 4999,
        'schema_id' => $cameraPro['id'],
        'attributes' => [
            'sensor.megapixels' => '30.5', 'body.weight' => '1400', 'sensor.size' => 'full-frame',
        ],
    ]);
    $note = $make([
        'title' => 'Field Notebook', 'slug' => 'field-notebook', 'sku' => 'NOTE-1', 'status' => 'published', 'price_amount' => 1200,
        'schema_id' => $notebook['id'],
        'attributes' => ['page.count' => '200'],
    ]);
    $draft = $make([
        'title' => 'Secret Draft Camera', 'slug' => 'secret-draft-camera', 'sku' => 'CAM-DRAFT', 'status' => 'draft',
        'schema_id' => $camera['id'],
        'attributes' => ['sensor.megapixels' => '99.9', 'lens.mount' => 'z'],
    ]);

    return [
        'owner' => $owner,
        'camera' => $camera['id'], 'camera_pro' => $cameraPro['id'], 'notebook' => $notebook['id'],
        'cam_a1' => $camA1['id'], 'cam_a1_variant' => $camA1['variant'],
        'cam_a2' => $camA2['id'], 'cam_a2_variant' => $camA2['variant'],
        'cam_b1' => $camB1['id'], 'cam_b1_variant' => $camB1['variant'],
        'note' => $note['id'], 'note_variant' => $note['variant'],
        'draft' => $draft['id'], 'draft_variant' => $draft['variant'],
    ];
}

/** @param list<array<string, mixed>> $rows */
function compareRow(array $rows, string $key): ?array
{
    foreach ($rows as $row) {
        if ($row['key'] === $key) {
            return $row;
        }
    }

    return null;
}

it('ShoppingToolsCompare converts units through the typed registry and refuses unknown or cross-dimension conversions', function (): void {
    $units = app(UnitConversions::class);
    expect($units->convert('1.5', 'kg', 'g'))->toBe('1500')
        ->and($units->convert('1500', 'g', 'kg'))->toBe('1.5')
        ->and($units->convert('2', 'L', 'mL'))->toBe('2000')
        ->and($units->convert('250', 'mL', 'L'))->toBe('0.25')
        ->and($units->convert('10', 'in', 'cm'))->toBe('25.4')
        ->and($units->convert('25.4', 'cm', 'in'))->toBe('10')
        ->and($units->convert('1', 'lb', 'g'))->toBe('453.59237')
        ->and($units->convert('500', 'mg', 'g'))->toBe('0.5')
        ->and($units->dimension('kg'))->toBe('mass')
        ->and($units->dimension('mL'))->toBe('volume')
        ->and($units->dimension('in'))->toBe('length')
        ->and($units->canonicalUnit('mass'))->toBe('g')
        ->and($units->canonicalUnit('volume'))->toBe('mL')
        ->and($units->canonicalUnit('length'))->toBe('mm')
        ->and($units->convert('1', 'kg', 'L'))->toBeNull()
        ->and($units->convert('1', 'MP', 'g'))->toBeNull()
        ->and($units->convert('1', 'kg', 'furlongs'))->toBeNull()
        ->and($units->dimension('MP'))->toBeNull();
});

it('ShoppingToolsCompare compares shared semantic attributes with normalized values and explicit absences', function (): void {
    $catalog = compareCatalog();
    $view = app(CompareProducts::class)->compare([
        ['variant_id' => $catalog['cam_a1_variant']],
        ['variant_id' => $catalog['cam_a2_variant']],
    ]);
    expect($view)->toBeInstanceOf(ComparisonView::class);
    $data = $view->jsonSerialize();
    expect($data['compatible'])->toBeTrue()
        ->and($data['specialized_attributes_omitted'])->toBeFalse()
        ->and($data['omitted_reason'])->toBeNull()
        ->and($data['removed'])->toBe([])
        ->and($data['comparison_groups'])->toBe(['cameras'])
        ->and($data['selections'])->toHaveCount(2)
        ->and($data['selections'][0]['title'])->toBe('Alpha Camera')
        ->and($data['selections'][0]['sku'])->toBe('CAM-A1')
        ->and($data['selections'][0]['money']['amount'])->toBe(2499)
        ->and($data['selections'][0]['availability'])->toBe('in_stock')
        ->and($data['selections'][1]['money']['amount'])->toBe(2599);

    $keys = array_column($data['rows'], 'key');
    expect($keys)->toContain('price', 'availability', 'sku', 'type', 'sensor.megapixels', 'body.weight', 'item.mass', 'lens.mount', 'body.stabilized')
        ->and($keys)->not->toContain('care.warranty');

    // 24.2 and 24.20 are the same typed value once normalized.
    $megapixels = compareRow($data['rows'], 'sensor.megapixels');
    expect($megapixels['kind'])->toBe('attribute')
        ->and($megapixels['unit'])->toBe('MP')
        ->and($megapixels['differs'])->toBeFalse()
        ->and($megapixels['cells'][0]['display'])->toBe('24.2 MP')
        ->and($megapixels['cells'][1]['display'])->toBe('24.2 MP');

    // The mass row is defined in kilograms: values normalize to grams
    // before comparison, so 1.5 kg and 1.50 kg do not differ.
    $mass = compareRow($data['rows'], 'item.mass');
    expect($mass['unit'])->toBe('kg')
        ->and($mass['differs'])->toBeFalse()
        ->and($mass['cells'][0]['display'])->toBe('1.5 kg')
        ->and($mass['cells'][0]['normalized_value'])->toBe('1500')
        ->and($mass['cells'][0]['normalized_unit'])->toBe('g')
        ->and($mass['cells'][1]['normalized_value'])->toBe('1500');

    // The weight row is defined in grams: 1500 g normalizes to itself,
    // and the product that never stored a weight shows an explicit
    // absence — never a zero.
    $weight = compareRow($data['rows'], 'body.weight');
    expect($weight['cells'][0]['specified'])->toBeTrue()
        ->and($weight['cells'][0]['display'])->toBe('1500 g')
        ->and($weight['cells'][0]['normalized_value'])->toBe('1500')
        ->and($weight['cells'][0]['normalized_unit'])->toBe('g')
        ->and($weight['cells'][1]['specified'])->toBeFalse()
        ->and($weight['cells'][1]['display'])->toBe('Not specified')
        ->and($weight['cells'][1]['value'])->toBeNull();

    $mount = compareRow($data['rows'], 'lens.mount');
    expect($mount['differs'])->toBeTrue()
        ->and($mount['cells'][0]['display'])->toBe('rf')
        ->and($mount['cells'][1]['display'])->toBe('e');

    $stabilized = compareRow($data['rows'], 'body.stabilized');
    expect($stabilized['cells'][0]['display'])->toBe('Yes')
        ->and($stabilized['cells'][0]['value'])->toBeTrue()
        ->and($stabilized['cells'][1]['specified'])->toBeFalse()
        ->and($stabilized['cells'][1]['display'])->toBe('Not specified');

    $price = compareRow($data['rows'], 'price');
    expect($price['kind'])->toBe('basic')
        ->and($price['differs'])->toBeTrue()
        ->and($price['cells'][0]['display'])->toBe('USD 24.99')
        ->and($price['cells'][1]['display'])->toBe('USD 25.99');
});

it('ShoppingToolsCompare unions attributes across two schemas in one group and marks fields a schema does not define as absent', function (): void {
    $catalog = compareCatalog();
    $data = app(CompareProducts::class)->compare([
        ['product_id' => $catalog['cam_a1']],
        ['product_id' => $catalog['cam_b1']],
    ])->jsonSerialize();
    expect($data['compatible'])->toBeTrue()
        ->and($data['selections'][1]['title'])->toBe('Pro Camera')
        ->and($data['selections'][1]['schema_key'])->toBe('camera-pro');

    // sensor.size exists only in the pro schema: the other column is an
    // explicit absence, not a dropped row.
    $size = compareRow($data['rows'], 'sensor.size');
    expect($size)->not->toBeNull()
        ->and($size['cells'][0]['specified'])->toBeFalse()
        ->and($size['cells'][0]['display'])->toBe('Not specified')
        ->and($size['cells'][1]['display'])->toBe('full-frame');

    // lens.mount exists only in the base schema.
    $mount = compareRow($data['rows'], 'lens.mount');
    expect($mount['cells'][0]['display'])->toBe('rf')
        ->and($mount['cells'][1]['specified'])->toBeFalse()
        ->and($mount['cells'][1]['display'])->toBe('Not specified');

    // The shared weight definition keeps one row across both schemas.
    $weight = compareRow($data['rows'], 'body.weight');
    expect($weight['cells'][0]['display'])->toBe('1500 g')
        ->and($weight['cells'][1]['display'])->toBe('1400 g')
        ->and($weight['differs'])->toBeTrue();
});

it('ShoppingToolsCompare exposes only common basics with an explanation across incompatible groups', function (): void {
    $catalog = compareCatalog();
    $data = app(CompareProducts::class)->compare([
        ['variant_id' => $catalog['cam_a1_variant']],
        ['variant_id' => $catalog['note_variant']],
    ])->jsonSerialize();
    expect($data['compatible'])->toBeFalse()
        ->and($data['specialized_attributes_omitted'])->toBeTrue()
        ->and($data['omitted_reason'])->toBeString()->not->toBe('')
        ->and($data['comparison_groups'])->toBe(['cameras', 'stationery']);
    $keys = array_column($data['rows'], 'key');
    expect($keys)->toBe(['price', 'availability', 'sku', 'type']);
    expect(compareRow($data['rows'], 'price')['cells'][1]['display'])->toBe('USD 12.00');
    expect(json_encode($data, JSON_THROW_ON_ERROR))->not->toContain('page.count', 'sensor.megapixels');
});

it('ShoppingToolsCompare uses the selected variant basics instead of the product default variant', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $lamp = app(SaveProduct::class)->save($owner, catalogProductInput($image, ['status' => 'published']));
    app(SaveVariants::class)->save($owner, catalogVariantMatrix($lamp['id'], $lamp['version'], $image));
    $other = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'title' => 'Desk Lamp', 'slug' => 'desk-lamp', 'sku' => 'LAMP-002', 'status' => 'published', 'price_amount' => 1899,
    ]));
    $chalk = (string) DB::table('product_variants')->where('product_id', $lamp['id'])->where('sku', 'LAMP-CHALK-S')->value('id');
    $pineM = (string) DB::table('product_variants')->where('product_id', $lamp['id'])->where('sku', 'LAMP-PINE-M')->value('id');
    $otherVariant = (string) DB::table('product_variants')->where('product_id', $other['id'])->value('id');

    // The chosen variant's own price, SKU and option label win over the
    // product's default variant.
    $data = app(CompareProducts::class)->compare([
        ['variant_id' => $chalk],
        ['variant_id' => $otherVariant],
    ])->jsonSerialize();
    expect($data['selections'][0]['sku'])->toBe('LAMP-CHALK-S')
        ->and($data['selections'][0]['money']['amount'])->toBe(2699)
        ->and($data['selections'][0]['variant_label'])->toBe('Chalk / S');

    // A product selection resolves to its default listed variant.
    $byProduct = app(CompareProducts::class)->compare([
        ['product_id' => $lamp['id']],
        ['product_id' => $other['id']],
    ])->jsonSerialize();
    expect($byProduct['selections'][0]['sku'])->toBe('LAMP-PINE-S')
        ->and($byProduct['selections'][0]['money']['amount'])->toBe(2499)
        ->and($byProduct['selections'][0]['variant_label'])->toBe('Pine / S');

    // Selecting the same variant twice is one column, so the pair
    // collapses below the minimum and is refused.
    expect(fn () => app(CompareProducts::class)->compare([
        ['variant_id' => $chalk],
        ['variant_id' => $chalk],
    ]))->toThrow(ValidationException::class);

    // An unlisted variant is an invalid selection: removed with a
    // notice while the valid pair still compares.
    $withUnlisted = app(CompareProducts::class)->compare([
        ['variant_id' => $chalk],
        ['variant_id' => $pineM],
        ['variant_id' => $otherVariant],
    ])->jsonSerialize();
    expect($withUnlisted['selections'])->toHaveCount(2)
        ->and($withUnlisted['removed'])->toHaveCount(1)
        ->and($withUnlisted['removed'][0]['selection'])->toBe($pineM)
        ->and($withUnlisted['removed'][0]['reason'])->toBe('unavailable');
});

it('ShoppingToolsCompare rejects one-item and five-item selections and malformed entries', function (): void {
    $catalog = compareCatalog();
    $compare = app(CompareProducts::class);
    expect(fn () => $compare->compare([
        ['variant_id' => $catalog['cam_a1_variant']],
    ]))->toThrow(ValidationException::class);
    expect(fn () => $compare->compare([]))->toThrow(ValidationException::class);
    expect(fn () => $compare->compare([
        ['variant_id' => $catalog['cam_a1_variant']],
        ['variant_id' => $catalog['cam_a2_variant']],
        ['variant_id' => $catalog['cam_b1_variant']],
        ['variant_id' => $catalog['note_variant']],
        ['product_id' => $catalog['cam_a1']],
    ]))->toThrow(ValidationException::class);
    expect(fn () => $compare->compare([
        ['variant_id' => $catalog['cam_a1_variant']],
        ['variant_id' => $catalog['cam_a2_variant']],
        ['variant_id' => $catalog['cam_b1_variant']],
        ['variant_id' => $catalog['note_variant']],
    ]))->not->toThrow(ValidationException::class);
    expect(fn () => $compare->compare([
        ['variant_id' => $catalog['cam_a1_variant']],
        ['color' => 'red'],
    ]))->toThrow(ValidationException::class);
});

it('ShoppingToolsCompare removes unpublished and unknown selections with a generic notice and never leaks their facts', function (): void {
    $catalog = compareCatalog();
    $data = app(CompareProducts::class)->compare([
        ['variant_id' => $catalog['cam_a1_variant']],
        ['variant_id' => $catalog['draft_variant']],
        ['variant_id' => $catalog['cam_a2_variant']],
        ['variant_id' => (string) Str::uuid()],
    ])->jsonSerialize();
    expect($data['selections'])->toHaveCount(2)
        ->and($data['removed'])->toHaveCount(2)
        ->and($data['removed'][0]['selection'])->toBe($catalog['draft_variant'])
        ->and($data['removed'][0]['reason'])->toBe('unavailable');
    $encoded = json_encode($data, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('Secret Draft Camera', 'CAM-DRAFT', 'secret-draft-camera', '99.9');

    // When removals leave a single available item there is nothing to
    // compare, and the refusal stays generic.
    expect(fn () => app(CompareProducts::class)->compare([
        ['variant_id' => $catalog['cam_a1_variant']],
        ['product_id' => $catalog['draft']],
    ]))->toThrow(ValidationException::class);
});

it('ShoppingToolsCompare serves the read model over the JSON and HTML surfaces', function (): void {
    $catalog = compareCatalog();
    $query = http_build_query(['items' => [$catalog['cam_a1_variant'], $catalog['cam_a2_variant']]]);
    $json = $this->getJson('/store-api/v1/compare?'.$query);
    $json->assertOk()
        ->assertJsonPath('selections.0.title', 'Alpha Camera')
        ->assertJsonPath('compatible', true);
    $rows = collect($json->json('rows'));
    expect($rows->firstWhere('key', 'body.weight')['cells'][1]['display'])->toBe('Not specified')
        ->and($rows->firstWhere('key', 'item.mass')['cells'][0]['normalized_value'])->toBe('1500');

    $page = $this->get('/compare?'.$query);
    $page->assertOk()
        ->assertSee('Alpha Camera')
        ->assertSee('Beta Camera')
        ->assertSee('Megapixels')
        ->assertSee('Not specified');

    $mixed = $this->get('/compare?'.http_build_query(['items' => [$catalog['cam_a1_variant'], $catalog['note_variant']]]));
    $mixed->assertOk()
        ->assertSee('Field Notebook')
        ->assertDontSee('Megapixels');
});

it('ShoppingToolsCompare supplies replace candidates and the cart revision to the HTML surface only', function (): void {
    $catalog = compareCatalog();
    $query = http_build_query(['items' => [$catalog['cam_a1_variant'], $catalog['cam_a2_variant']]]);
    $page = $this->get('/compare?'.$query);
    $page->assertOk()
        ->assertViewHas('cartRevision', 1)
        ->assertSee('id="comparison-table"', false)
        ->assertSee('data-candidates', false);
    expect($page->getContent())->not->toContain('Secret Draft Camera', 'CAM-DRAFT');
    $candidates = collect($page->viewData('candidates'));
    $variantIds = $candidates->pluck('variant_id')->all();
    expect($variantIds)->toContain($catalog['cam_a1_variant'], $catalog['cam_a2_variant'], $catalog['cam_b1_variant'], $catalog['note_variant'])
        ->and($variantIds)->not->toContain($catalog['draft_variant'])
        ->and($candidates->pluck('product_id')->duplicates()->all())->toBe([])
        ->and($candidates->firstWhere('variant_id', $catalog['cam_a1_variant'])['title'])->toBe('Alpha Camera');

    $json = $this->getJson('/store-api/v1/compare?'.$query);
    $json->assertOk();
    expect(array_keys($json->json()))->not->toContain('candidates', 'cartRevision');
});

it('ShoppingToolsCompare fails invalid counts through the generic validation envelope without leaking draft facts', function (): void {
    $catalog = compareCatalog();
    $single = $this->getJson('/store-api/v1/compare?'.http_build_query(['items' => [$catalog['cam_a1_variant']]]));
    $single->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION');

    $withDraft = $this->getJson('/store-api/v1/compare?'.http_build_query(['items' => [$catalog['cam_a1_variant'], $catalog['draft_variant'], $catalog['cam_a2_variant']]]));
    $withDraft->assertOk()
        ->assertJsonPath('removed.0.selection', $catalog['draft_variant']);
    expect($withDraft->getContent())->not->toContain('Secret Draft Camera', 'CAM-DRAFT');
});
