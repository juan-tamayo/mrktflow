<?php

namespace Database\Seeders;

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveProductSchema;
use App\Domain\Catalog\Actions\SaveVariants;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\Catalog\Support\CatalogMediaUploadAuthorization;
use App\Domain\Catalog\Support\ProductAttributes;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\ProcessMedia;
use App\Domain\Platform\Actions\StoreMedia;
use App\Domain\Pricing\Actions\SaveCoupon;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BrowserShopCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Browser shop fixtures are refused outside testing.');
        }
        $database = (string) config('database.connections.mysql.database');
        if (! in_array($database, ['mrktflow_test', 'mrktflow_browser'], true)) {
            throw new RuntimeException('Browser shop fixtures require an isolated test database.');
        }
        $owner = StaffAccount::query()->orderBy('created_at')->first();
        if (! $owner instanceof StaffAccount) {
            throw new RuntimeException('Install a store owner before seeding shop catalog fixtures.');
        }

        $this->republishStudioLamp($owner);
        $this->ensureLightingGuide($owner);
        $this->ensureFreeCoupon($owner);
        $this->ensureComparisonCatalog($owner);
    }

    private function republishStudioLamp(StaffAccount $owner): void
    {
        $lamp = DB::table('products')->where('slug', 'studio-lamp')->first();
        if ($lamp === null || (string) $lamp->status !== 'archived') {
            return;
        }
        $media = DB::table('product_media')->where('product_id', $lamp->id)->where('sort_order', 0)->first();
        $variant = DB::table('product_variants')->where('product_id', $lamp->id)->where('is_default', true)->first();
        if ($media === null || $variant === null) {
            throw new RuntimeException('Archived Studio Lamp is missing its image or default variant.');
        }
        $categories = DB::table('product_categories')->where('product_id', $lamp->id)->pluck('category_id')->all();
        app(SaveProduct::class)->save($owner, [
            'id' => (string) $lamp->id,
            'version' => (int) $lamp->version,
            'title' => (string) $lamp->title,
            'slug' => 'studio-lamp',
            'description' => (string) $lamp->description,
            'status' => 'published',
            'sku' => (string) $variant->sku,
            'price_amount' => (int) $variant->price_amount,
            'image_asset_id' => (string) $media->media_asset_id,
            'image_alt' => (string) $media->alt,
            'category_ids' => array_values(array_map(strval(...), $categories)),
        ]);
    }

    private function ensureLightingGuide(StaffAccount $owner): void
    {
        $product = DB::table('products')->where('slug', 'studio-guide')->first();
        if ($product === null) {
            return;
        }
        $asset = DB::table('digital_assets')->where('product_id', $product->id)->orderBy('created_at')->first();
        if ($asset !== null) {
            DB::table('digital_assets')->where('id', $asset->id)->update([
                'label' => 'Lighting guide',
                'updated_at' => now(),
            ]);

            return;
        }
        $variantId = (string) DB::table('product_variants')->where('product_id', $product->id)->value('id');
        $versioned = app(VersionDigitalAsset::class)->version($owner, [
            'product_id' => (string) $product->id,
            'label' => 'Lighting guide',
            'file' => UploadedFile::fake()->createWithContent('studio-guide.txt', "Fictional studio lighting guide.\n"),
        ]);
        app(VersionDigitalAsset::class)->attach($owner, [
            'product_id' => (string) $product->id,
            'variant_id' => $variantId,
            'version_ids' => [$versioned['id']],
        ]);
    }

    private function ensureFreeCoupon(StaffAccount $owner): void
    {
        if (DB::table('coupons')->where('code_normalized', 'FREE100')->exists()) {
            return;
        }
        app(SaveCoupon::class)->save($owner, [
            'code' => 'FREE100',
            'name' => 'Browser free checkout',
            'mode' => 'percentage',
            'percent_numerator' => 100,
            'percent_denominator' => 100,
            'amount' => null,
            'min_eligible_subtotal' => 0,
            'starts_at' => '2026-01-01T00:00:00Z',
            'ends_at' => '2027-12-31T00:00:00Z',
            'max_redemptions' => 25,
            'max_redemptions_per_customer' => null,
            'status' => 'active',
            'product_ids' => [],
            'category_ids' => [],
        ]);
    }

    private function ensureComparisonCatalog(StaffAccount $owner): void
    {
        $image = $this->readyImage($owner);
        $camera = $this->schema($owner, 'camera', 'Camera', 'cameras', [
            ['key' => 'sensor.megapixels', 'type' => 'decimal', 'unit' => 'MP', 'required' => true, 'comparable' => true, 'filterable' => true, 'label' => 'Megapixels'],
            ['key' => 'body.weight', 'type' => 'integer', 'unit' => 'g', 'required' => false, 'comparable' => true, 'filterable' => false, 'label' => 'Weight'],
            ['key' => 'lens.mount', 'type' => 'enum', 'required' => true, 'comparable' => true, 'filterable' => true, 'label' => 'Lens mount', 'options' => ['rf', 'e', 'z']],
        ]);
        $cameraPro = $this->schema($owner, 'camera-pro', 'Camera Pro', 'cameras', [
            ['key' => 'sensor.megapixels', 'type' => 'decimal', 'unit' => 'MP', 'required' => true, 'comparable' => true, 'filterable' => true, 'label' => 'Megapixels'],
            ['key' => 'body.weight', 'type' => 'integer', 'unit' => 'g', 'required' => false, 'comparable' => true, 'filterable' => false, 'label' => 'Body weight'],
            ['key' => 'sensor.size', 'type' => 'string', 'required' => false, 'comparable' => true, 'filterable' => false, 'label' => 'Sensor size'],
        ]);
        $notebook = $this->schema($owner, 'notebook', 'Notebook', 'stationery', [
            ['key' => 'page.count', 'type' => 'integer', 'unit' => 'pp', 'required' => true, 'comparable' => true, 'filterable' => true, 'label' => 'Pages'],
        ]);

        $this->publishSimple($owner, $image, $camera, [
            'title' => 'Alpha Camera', 'slug' => 'alpha-camera', 'sku' => 'CAM-A1', 'price_amount' => 2499,
            'description' => 'A fictional camera for comparison journeys.',
            'image_alt' => 'Alpha camera body',
            'overrides' => ['sensor.megapixels' => '24.2', 'lens.mount' => 'rf', 'body.weight' => '1500'],
        ]);
        $this->publishSimple($owner, $image, $camera, [
            'title' => 'Beta Camera', 'slug' => 'beta-camera', 'sku' => 'CAM-A2', 'price_amount' => 2599,
            'description' => 'A second fictional camera for comparison journeys.',
            'image_alt' => 'Beta camera body',
            'overrides' => ['sensor.megapixels' => '24.20', 'lens.mount' => 'e'],
        ]);
        $this->publishSimple($owner, $image, $cameraPro, [
            'title' => 'Pro Camera', 'slug' => 'pro-camera', 'sku' => 'CAM-B1', 'price_amount' => 4999,
            'description' => 'A fictional pro camera for comparison journeys.',
            'image_alt' => 'Pro camera body',
            'overrides' => ['sensor.megapixels' => '30.5', 'body.weight' => '1400', 'sensor.size' => 'full-frame'],
        ]);
        $this->publishSimple($owner, $image, $notebook, [
            'title' => 'Field Notebook', 'slug' => 'field-notebook', 'sku' => 'NOTE-1', 'price_amount' => 1200,
            'description' => 'A fictional notebook in a different comparison group.',
            'image_alt' => 'Field notebook cover',
            'overrides' => ['page.count' => '200'],
        ]);
        $gamma = $this->publishSimple($owner, $image, $camera, [
            'title' => 'Gamma Camera', 'slug' => 'gamma-camera', 'sku' => 'CAM-G1', 'price_amount' => 1899,
            'description' => 'A fifth fictional camera used when the compare tray is full.',
            'image_alt' => 'Gamma camera body',
            'overrides' => ['sensor.megapixels' => '20.1', 'lens.mount' => 'rf'],
        ]);
        if ($gamma !== null) {
            app(SaveVariants::class)->save($owner, [
                'id' => $gamma['id'],
                'version' => $gamma['version'],
                'options' => [['name' => 'Finish', 'values' => ['Pine']]],
                'variants' => [[
                    'sku' => 'CAM-G1-PINE',
                    'price_amount' => 1899,
                    'is_default' => true,
                    'available' => true,
                    'options' => ['Finish' => 'Pine'],
                ]],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $attributes
     * @return array{id: string}
     */
    private function schema(StaffAccount $owner, string $key, string $label, string $group, array $attributes): array
    {
        $existing = DB::table('product_schemas')->where('semantic_key', $key)->first();
        if ($existing !== null) {
            return ['id' => (string) $existing->id];
        }

        $aligned = [];
        foreach ($attributes as $attribute) {
            $aligned[] = $this->alignAttribute($attribute);
        }

        return app(SaveProductSchema::class)->save($owner, [
            'key' => $key,
            'label' => $label,
            'comparison_group' => $group,
            'default_template_key' => 'product.default',
            'attributes' => $aligned,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attribute
     * @return array<string, mixed>
     */
    private function alignAttribute(array $attribute): array
    {
        $existing = DB::table('attribute_definitions')->where('semantic_key', $attribute['key'])->first();
        if ($existing === null) {
            return $attribute;
        }
        $options = [];
        if ((string) $existing->type === 'enum') {
            foreach (DB::table('attribute_enum_options')->where('attribute_definition_id', $existing->id)->orderBy('sort_order')->pluck('option_value') as $option) {
                if (is_string($option)) {
                    $options[] = $option;
                }
            }
        }
        $aligned = [
            'key' => $attribute['key'],
            'type' => (string) $existing->type,
            'unit' => is_string($existing->unit) ? $existing->unit : null,
            'required' => (bool) ($attribute['required'] ?? false),
            'comparable' => (bool) $existing->comparable,
            'filterable' => (bool) $existing->filterable,
            'label' => is_string($attribute['label'] ?? null) ? $attribute['label'] : (string) $attribute['key'],
        ];
        if ($aligned['type'] === 'enum') {
            $aligned['options'] = $options;
        }

        return $aligned;
    }

    /**
     * @param  array{id: string}  $schema
     * @param  array{title: string, slug: string, sku: string, price_amount: int, description: string, image_alt: string, overrides: array<string, mixed>}  $input
     * @return array{id: string, version: int}|null
     */
    private function publishSimple(StaffAccount $owner, string $image, array $schema, array $input): ?array
    {
        if (DB::table('products')->where('slug', $input['slug'])->exists()) {
            return null;
        }
        $values = $this->attributesForSchema($schema['id'], $input['overrides']);

        return app(SaveProduct::class)->save($owner, [
            'title' => $input['title'],
            'slug' => $input['slug'],
            'description' => $input['description'],
            'status' => 'published',
            'sku' => $input['sku'],
            'price_amount' => $input['price_amount'],
            'image_asset_id' => $image,
            'image_alt' => $input['image_alt'],
            'schema_id' => $schema['id'],
            'attributes' => $values,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attributesForSchema(string $schemaId, array $overrides): array
    {
        $versionId = (string) DB::table('product_schemas')->where('id', $schemaId)->value('current_version_id');
        $fields = app(ProductAttributes::class)->fieldsForVersion($versionId);
        $values = [];
        foreach ($fields as $field) {
            if (array_key_exists($field['key'], $overrides)) {
                $values[$field['key']] = $overrides[$field['key']];

                continue;
            }
            if (! $field['required']) {
                continue;
            }
            $values[$field['key']] = match ($field['type']) {
                'decimal' => '24.2',
                'integer' => '1',
                'boolean' => true,
                'enum' => $field['options'][0] ?? 'rf',
                default => 'sample',
            };
        }

        return $values;
    }

    private function readyImage(StaffAccount $owner): string
    {
        $existing = DB::table('product_media')->value('media_asset_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        if (! is_string($png) || $png === '') {
            throw new RuntimeException('Shop fixture image could not be decoded.');
        }
        $path = sys_get_temp_dir().'/mrktflow-shop-'.uniqid('', true).'.png';
        file_put_contents($path, $png);
        $file = new UploadedFile($path, 'shop.png', 'image/png', null, true);
        $id = app(StoreMedia::class)->store($file, 'public_image', new CatalogMediaUploadAuthorization($owner));
        app(ProcessMedia::class)->process($id);
        @unlink($path);

        return $id;
    }
}
