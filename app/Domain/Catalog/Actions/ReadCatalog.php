<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\Catalog\Exceptions\CatalogCategoryNotFound;
use App\Domain\Catalog\Exceptions\CatalogCategoryUnpublished;
use App\Domain\Catalog\Exceptions\CatalogProductNotFound;
use App\Domain\Catalog\Exceptions\CatalogProductUnpublished;
use App\Domain\Catalog\Support\ProductAttributes;
use App\Domain\Catalog\Support\VariantAvailability;
use App\Domain\Catalog\Support\VariantOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReadCatalog implements CatalogReader
{
    public function publicProduct(string $idOrSlug): array
    {
        $row = $this->loadProduct($idOrSlug);
        if ($row === null) {
            throw new CatalogProductNotFound;
        }
        if ($row['status'] !== 'published') {
            throw new CatalogProductUnpublished;
        }
        $product = $this->productSnapshot($row, false);
        $product['breadcrumbs'] = $this->productBreadcrumbs($product['id']);
        $product['option_axes'] = $this->optionAxes($product['id']);
        $product['variants'] = $this->variants($product['id'], $product['image'], false, $product['type'] === 'digital');
        $product['schema'] = $this->publicSchema($row);
        $product['typed_attributes'] = app(ProductAttributes::class)->publicAttributes($product['id']);
        $states = [];
        foreach ($product['variants'] as $index => $variant) {
            $states[] = $variant['availability'];
            $product['variants'][$index]['available'] = VariantAvailability::sellable($variant['availability']);
        }
        $product['availability'] = VariantAvailability::productState($states);
        $product['available'] = VariantAvailability::sellable($product['availability']);

        return $product;
    }

    public function staffProduct(string $idOrSlug): array
    {
        $row = $this->loadProduct($idOrSlug);
        if ($row === null) {
            throw new CatalogProductNotFound;
        }

        $product = $this->productSnapshot($row, true);
        $product['version'] = (int) $row['version'];
        $product['image']['assetId'] = is_string($row['media_asset_id'] ?? null) ? $row['media_asset_id'] : '';
        $categoryIds = [];
        foreach (DB::table('product_categories')->where('product_id', $product['id'])->orderBy('category_id')->pluck('category_id') as $categoryId) {
            if (is_string($categoryId)) {
                $categoryIds[] = $categoryId;
            }
        }
        $product['category_ids'] = $categoryIds;
        $product['option_axes'] = $this->optionAxes($product['id']);
        $product['variants'] = $this->variants($product['id'], $product['image'], true, $product['type'] === 'digital');
        $schemaId = $this->schemaIdFromVersion(is_string($row['schema_version_id'] ?? null) ? $row['schema_version_id'] : null);
        $product['schema_id'] = $schemaId;
        $product['attributes'] = app(ProductAttributes::class)->staffValues($product['id']);
        if ($product['type'] === 'digital') {
            $product['digital_assets'] = $this->staffDigitalAssets($product['id']);
        }

        return $product;
    }

    public function publicCategory(string $path): array
    {
        $row = DB::table('categories')->where('path', $path)->first();
        if ($row === null) {
            throw new CatalogCategoryNotFound;
        }
        if (! $this->lineageIsPublished((string) $row->path)) {
            throw new CatalogCategoryUnpublished;
        }
        $products = [];
        $records = DB::table('products')
            ->join('product_categories', 'products.id', '=', 'product_categories.product_id')
            ->join('product_variants', function ($join): void {
                $join->on('products.id', '=', 'product_variants.product_id')->where('product_variants.is_default', '=', true);
            })
            ->join('product_media', function ($join): void {
                $join->on('products.id', '=', 'product_media.product_id')->where('product_media.sort_order', '=', 0);
            })
            ->join('media_assets', 'product_media.media_asset_id', '=', 'media_assets.id')
            ->where('product_categories.category_id', $row->id)
            ->where('products.status', 'published')
            ->where('media_assets.kind', 'public_image')
            ->where('media_assets.status', 'ready')
            ->orderBy('products.title')
            ->orderBy('products.id')
            ->limit(24)
            ->select([
                'products.id', 'products.slug', 'products.title', 'product_variants.price_amount',
                'product_variants.currency', 'product_variants.exponent', 'product_media.alt', 'media_assets.public_key',
            ])
            ->get();
        foreach ($records as $product) {
            $publicKey = (string) $product->public_key;
            if (! preg_match('#^media/[0-9a-f-]{36}/[a-f0-9]{64}\.png$#D', $publicKey)) {
                continue;
            }
            $products[] = [
                'id' => (string) $product->id,
                'slug' => (string) $product->slug,
                'title' => (string) $product->title,
                'image' => ['url' => '/storage/'.$publicKey, 'alt' => (string) $product->alt],
                'money' => [
                    'amount' => (int) $product->price_amount,
                    'currency' => (string) $product->currency,
                    'exponent' => (int) $product->exponent,
                ],
            ];
        }
        $availability = app(VariantAvailability::class)->forProducts(array_column($products, 'id'));
        foreach ($products as $index => $product) {
            $summary = $availability[$product['id']] ?? ['available' => false, 'availability' => VariantAvailability::UNAVAILABLE];
            $products[$index]['available'] = $summary['available'];
            $products[$index]['availability'] = $summary['availability'];
        }

        return [
            'id' => (string) $row->id,
            'path' => (string) $row->path,
            'title' => (string) $row->title,
            'description' => (string) $row->description,
            'status' => (string) $row->status,
            'breadcrumbs' => $this->categoryBreadcrumbs((string) $row->path),
            'products' => $products,
        ];
    }

    public function staffCategory(string $idOrPath): array
    {
        $query = DB::table('categories');
        $row = Str::isUuid($idOrPath) ? $query->where('id', $idOrPath)->first() : $query->where('path', $idOrPath)->first();
        if ($row === null) {
            throw new CatalogCategoryNotFound;
        }

        return [
            'id' => (string) $row->id,
            'parent_id' => is_string($row->parent_id) ? $row->parent_id : null,
            'title' => (string) $row->title,
            'slug' => (string) $row->slug,
            'path' => (string) $row->path,
            'description' => (string) $row->description,
            'status' => (string) $row->status,
            'version' => (int) $row->version,
        ];
    }

    public function staffCategories(): array
    {
        $categories = [];
        foreach (DB::table('categories')->orderBy('path')->get() as $row) {
            $categories[] = [
                'id' => (string) $row->id,
                'parent_id' => is_string($row->parent_id) ? $row->parent_id : null,
                'title' => (string) $row->title,
                'slug' => (string) $row->slug,
                'path' => (string) $row->path,
                'status' => (string) $row->status,
                'version' => (int) $row->version,
            ];
        }

        return $categories;
    }

    public function staffSchema(string $id): array
    {
        $row = DB::table('product_schemas')->where('id', $id)->first();
        if ($row === null) {
            abort(404);
        }

        return $this->schemaSnapshot($row);
    }

    public function staffSchemas(): array
    {
        $schemas = [];
        foreach (DB::table('product_schemas')->orderBy('semantic_key')->orderBy('id')->get() as $row) {
            $schemas[] = $this->schemaSnapshot($row);
        }

        return $schemas;
    }

    public function publishedCount(): int
    {
        return (int) DB::table('products')->where('status', 'published')->count();
    }

    /** @return array<string, mixed>|null */
    private function loadProduct(string $idOrSlug): ?array
    {
        $query = DB::table('products')
            ->join('product_variants', function ($join): void {
                $join->on('products.id', '=', 'product_variants.product_id')->where('product_variants.is_default', '=', true);
            })
            ->join('product_media', function ($join): void {
                $join->on('products.id', '=', 'product_media.product_id')->where('product_media.sort_order', '=', 0);
            })
            ->join('media_assets', 'product_media.media_asset_id', '=', 'media_assets.id')
            ->select([
                'products.id', 'products.slug', 'products.title', 'products.description', 'products.type',
                'products.status', 'products.version', 'products.schema_version_id', 'product_variants.id as variant_id', 'product_variants.sku',
                'product_variants.price_amount', 'product_variants.currency', 'product_variants.exponent',
                'product_media.alt', 'product_media.media_asset_id', 'media_assets.public_key', 'media_assets.kind',
                'media_assets.status as media_status',
            ]);
        $record = Str::isUuid($idOrSlug)
            ? $query->where('products.id', $idOrSlug)->first()
            : $query->where('products.slug', $idOrSlug)->first();

        return is_object($record) ? get_object_vars($record) : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, slug: string, title: string, description: string, type: string, status: string, version?: int, image: array{url: string, alt: string, assetId?: string}, variant: array{id: string, sku: string, isDefault: bool, money: array{amount: int, currency: string, exponent: int}}}
     */
    private function productSnapshot(array $row, bool $staff): array
    {
        $publicKey = is_string($row['public_key'] ?? null) ? $row['public_key'] : '';
        $id = is_string($row['id'] ?? null) ? $row['id'] : '';
        $slug = is_string($row['slug'] ?? null) ? $row['slug'] : '';
        $title = is_string($row['title'] ?? null) ? $row['title'] : '';
        $description = is_string($row['description'] ?? null) ? $row['description'] : '';
        $type = is_string($row['type'] ?? null) ? $row['type'] : '';
        $status = is_string($row['status'] ?? null) ? $row['status'] : '';
        $alt = is_string($row['alt'] ?? null) ? $row['alt'] : '';
        $variantId = is_string($row['variant_id'] ?? null) ? $row['variant_id'] : '';
        $sku = is_string($row['sku'] ?? null) ? $row['sku'] : '';
        $currency = is_string($row['currency'] ?? null) ? $row['currency'] : '';
        $kind = is_string($row['kind'] ?? null) ? $row['kind'] : '';
        $mediaStatus = is_string($row['media_status'] ?? null) ? $row['media_status'] : '';
        if ($kind !== 'public_image' || $mediaStatus !== 'ready' || $id === '' || $variantId === ''
            || ! preg_match('#^media/[0-9a-f-]{36}/[a-f0-9]{64}\.png$#D', $publicKey)) {
            throw $staff ? new CatalogProductNotFound : new CatalogProductUnpublished;
        }

        return [
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'description' => $description,
            'type' => $type,
            'status' => $status,
            'image' => ['url' => '/storage/'.$publicKey, 'alt' => $alt],
            'variant' => [
                'id' => $variantId,
                'sku' => $sku,
                'isDefault' => true,
                'money' => [
                    'amount' => (int) $row['price_amount'],
                    'currency' => $currency,
                    'exponent' => (int) $row['exponent'],
                ],
            ],
        ];
    }

    /** @return list<array{title: string, url: string}> */
    private function productBreadcrumbs(string $productId): array
    {
        $membership = DB::table('product_categories')
            ->join('categories', 'product_categories.category_id', '=', 'categories.id')
            ->where('product_categories.product_id', $productId)
            ->where('categories.status', 'published')
            ->orderByDesc(DB::raw('CHAR_LENGTH(categories.path)'))
            ->orderBy('categories.path')
            ->select('categories.path')
            ->first();
        if ($membership === null) {
            return [];
        }

        return $this->categoryBreadcrumbs((string) $membership->path);
    }

    /** @return list<array{title: string, url: string}> */
    private function categoryBreadcrumbs(string $path): array
    {
        $crumbs = [];
        foreach ($this->lineagePaths($path) as $ancestorPath) {
            $ancestor = DB::table('categories')->where('path', $ancestorPath)->where('status', 'published')->first();
            if ($ancestor === null) {
                return [];
            }
            $crumbs[] = ['title' => (string) $ancestor->title, 'url' => '/categories/'.$ancestorPath];
        }

        return $crumbs;
    }

    private function lineageIsPublished(string $path): bool
    {
        $paths = $this->lineagePaths($path);
        $count = DB::table('categories')->whereIn('path', $paths)->where('status', 'published')->count();

        return $count === count($paths);
    }

    /** @return list<string> */
    private function lineagePaths(string $path): array
    {
        $segments = explode('/', $path);
        $paths = [];
        $prefix = '';
        foreach ($segments as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix.'/'.$segment;
            $paths[] = $prefix;
        }

        return $paths;
    }

    /**
     * @return list<array{name: string, values: list<string>}>
     */
    private function optionAxes(string $productId): array
    {
        $axes = [];
        foreach (DB::table('product_option_axes')->where('product_id', $productId)->orderBy('sort_order')->orderBy('name')->get() as $axis) {
            $values = [];
            foreach (DB::table('product_option_values')->where('axis_id', $axis->id)->orderBy('sort_order')->orderBy('value')->pluck('value') as $value) {
                if (is_string($value)) {
                    $values[] = $value;
                }
            }
            $axes[] = ['name' => (string) $axis->name, 'values' => $values];
        }

        return $axes;
    }

    /**
     * @param  array{url: string, alt: string, assetId?: string}  $fallbackImage
     * @return list<array{id: string, sku: string, label: string, isDefault: bool, available: bool, availability: string, options: list<array{name: string, value: string}>, money: array{amount: int, currency: string, exponent: int}, image: array{url: string, alt: string, assetId?: string}, weight_grams: int|null, length_mm: int|null, width_mm: int|null, height_mm: int|null, tracks_inventory?: bool, on_hand?: int|null, reserved?: int, available_quantity?: int|null, low_stock_threshold?: int|null, low_stock?: bool, max_quantity?: int, digital_files?: list<array{label: string, version: int, bytes: int, content_type: string, id?: string, asset_id?: string, status?: string, checksum?: string}>}>
     */
    private function variants(string $productId, array $fallbackImage, bool $staff, bool $digital): array
    {
        $variants = [];
        $rows = DB::table('product_variants')
            ->leftJoin('inventory_balances', 'product_variants.id', '=', 'inventory_balances.variant_id')
            ->where('product_variants.product_id', $productId)
            ->orderByDesc('product_variants.is_default')
            ->orderBy('product_variants.sku')
            ->orderBy('product_variants.id')
            ->select('product_variants.*', 'inventory_balances.on_hand as balance_on_hand', 'inventory_balances.reserved as balance_reserved', 'inventory_balances.low_stock_threshold as balance_threshold')
            ->get();
        foreach ($rows as $row) {
            $decoded = is_array($row->options) ? $row->options : json_decode(is_string($row->options) ? $row->options : '[]', true);
            $options = [];
            if (is_array($decoded)) {
                foreach ($decoded as $option) {
                    if (is_array($option) && is_string($option['name'] ?? null) && is_string($option['value'] ?? null)) {
                        $options[] = ['name' => $option['name'], 'value' => $option['value']];
                    }
                }
            }
            $image = $fallbackImage;
            $mediaId = is_string($row->media_asset_id) ? $row->media_asset_id : null;
            if ($mediaId !== null) {
                $asset = DB::table('media_assets')->where('id', $mediaId)->first();
                $publicKey = is_object($asset) && is_string($asset->public_key) ? $asset->public_key : '';
                $kind = is_object($asset) && is_string($asset->kind) ? $asset->kind : '';
                $status = is_object($asset) && is_string($asset->status) ? $asset->status : '';
                if ($kind === 'public_image' && $status === 'ready' && preg_match('#^media/[0-9a-f-]{36}/[a-f0-9]{64}\.png$#D', $publicKey) === 1) {
                    $alt = is_string($row->image_alt) && $row->image_alt !== '' ? $row->image_alt : $fallbackImage['alt'];
                    $image = ['url' => '/storage/'.$publicKey, 'alt' => $alt];
                    if ($staff) {
                        $image['assetId'] = $mediaId;
                    }
                }
            }
            $listed = (bool) $row->available;
            $tracks = (bool) $row->tracks_inventory;
            $onHand = $row->balance_on_hand !== null ? (int) $row->balance_on_hand : null;
            $reserved = $row->balance_reserved !== null ? (int) $row->balance_reserved : 0;
            $digitalFiles = $digital
                ? ($staff ? $this->staffDigitalFiles((string) $row->id) : $this->publicDigitalFiles((string) $row->id))
                : [];
            $hasReadyDigital = $digital && $this->hasReadyDigitalFile((string) $row->id);
            $availability = VariantAvailability::state($listed, $tracks, $onHand, $reserved, $digital, $hasReadyDigital);
            $variant = [
                'id' => (string) $row->id,
                'sku' => (string) $row->sku,
                'label' => VariantOptions::label($options),
                'isDefault' => (bool) $row->is_default,
                'available' => $listed,
                'availability' => $availability,
                'options' => $options,
                'money' => [
                    'amount' => (int) $row->price_amount,
                    'currency' => (string) $row->currency,
                    'exponent' => (int) $row->exponent,
                ],
                'image' => $image,
                'weight_grams' => $staff && $row->weight_grams !== null ? (int) $row->weight_grams : null,
                'length_mm' => $staff && $row->length_mm !== null ? (int) $row->length_mm : null,
                'width_mm' => $staff && $row->width_mm !== null ? (int) $row->width_mm : null,
                'height_mm' => $staff && $row->height_mm !== null ? (int) $row->height_mm : null,
            ];
            if ($staff) {
                $remaining = $tracks && $onHand !== null ? $onHand - $reserved : null;
                $threshold = $row->balance_threshold !== null ? (int) $row->balance_threshold : null;
                $variant['tracks_inventory'] = $tracks;
                $variant['on_hand'] = $onHand;
                $variant['reserved'] = $reserved;
                $variant['available_quantity'] = $remaining;
                $variant['low_stock_threshold'] = $threshold;
                $variant['low_stock'] = $tracks && $threshold !== null && $remaining !== null && $remaining <= $threshold;
            }
            if ($digital) {
                $variant['max_quantity'] = 1;
                $variant['digital_files'] = $digitalFiles;
            }
            $variants[] = $variant;
        }

        return $variants;
    }

    private function hasReadyDigitalFile(string $variantId): bool
    {
        return DB::table('variant_digital_assets')
            ->join('digital_asset_versions', 'variant_digital_assets.digital_asset_version_id', '=', 'digital_asset_versions.id')
            ->where('variant_digital_assets.variant_id', $variantId)
            ->where('digital_asset_versions.status', 'ready')
            ->exists();
    }

    /**
     * @return list<array{label: string, version: int, bytes: int, content_type: string}>
     */
    private function publicDigitalFiles(string $variantId): array
    {
        $files = [];
        foreach ($this->attachedDigitalRows($variantId) as $row) {
            if ((string) $row->status !== 'ready') {
                continue;
            }
            $files[] = [
                'label' => (string) $row->label,
                'version' => (int) $row->version,
                'bytes' => (int) $row->bytes,
                'content_type' => (string) $row->content_type,
            ];
        }

        return $files;
    }

    /**
     * @return list<array{label: string, version: int, bytes: int, content_type: string, id: string, asset_id: string, status: string, checksum: string}>
     */
    private function staffDigitalFiles(string $variantId): array
    {
        $files = [];
        foreach ($this->attachedDigitalRows($variantId) as $row) {
            $files[] = [
                'label' => (string) $row->label,
                'version' => (int) $row->version,
                'bytes' => (int) $row->bytes,
                'content_type' => (string) $row->content_type,
                'id' => (string) $row->id,
                'asset_id' => (string) $row->asset_id,
                'status' => (string) $row->status,
                'checksum' => (string) $row->checksum,
            ];
        }

        return $files;
    }

    /** @return Collection<int, \stdClass> */
    private function attachedDigitalRows(string $variantId): Collection
    {
        return DB::table('variant_digital_assets')
            ->join('digital_asset_versions', 'variant_digital_assets.digital_asset_version_id', '=', 'digital_asset_versions.id')
            ->join('digital_assets', 'variant_digital_assets.digital_asset_id', '=', 'digital_assets.id')
            ->where('variant_digital_assets.variant_id', $variantId)
            ->orderBy('variant_digital_assets.sort_order')
            ->orderBy('digital_assets.id')
            ->select([
                'digital_asset_versions.id',
                'digital_assets.id as asset_id',
                'digital_assets.label',
                'digital_asset_versions.version',
                'digital_asset_versions.status',
                'digital_asset_versions.bytes',
                'digital_asset_versions.content_type',
                'digital_asset_versions.checksum',
            ])
            ->get();
    }

    /**
     * @return list<array{id: string, label: string, versions: list<array{id: string, version: int, status: string, bytes: int, content_type: string}>}>
     */
    private function staffDigitalAssets(string $productId): array
    {
        $assets = [];
        foreach (DB::table('digital_assets')->where('product_id', $productId)->orderBy('created_at')->orderBy('id')->get() as $asset) {
            $versions = [];
            foreach (DB::table('digital_asset_versions')->where('digital_asset_id', $asset->id)->orderBy('version')->get() as $version) {
                $versions[] = [
                    'id' => (string) $version->id,
                    'version' => (int) $version->version,
                    'status' => (string) $version->status,
                    'bytes' => (int) $version->bytes,
                    'content_type' => (string) $version->content_type,
                ];
            }
            $assets[] = [
                'id' => (string) $asset->id,
                'label' => (string) $asset->label,
                'versions' => $versions,
            ];
        }

        return $assets;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, key: string, comparison_group: string}|null
     */
    private function publicSchema(array $row): ?array
    {
        $versionId = is_string($row['schema_version_id'] ?? null) ? $row['schema_version_id'] : null;
        if ($versionId === null) {
            return null;
        }
        $version = DB::table('product_schema_versions')
            ->join('product_schemas', 'product_schema_versions.product_schema_id', '=', 'product_schemas.id')
            ->where('product_schema_versions.id', $versionId)
            ->select(['product_schemas.id', 'product_schemas.semantic_key', 'product_schema_versions.comparison_group'])
            ->first();
        if ($version === null) {
            return null;
        }

        return [
            'id' => (string) $version->id,
            'key' => (string) $version->semantic_key,
            'comparison_group' => (string) $version->comparison_group,
        ];
    }

    private function schemaIdFromVersion(?string $versionId): ?string
    {
        if ($versionId === null) {
            return null;
        }
        $schemaId = DB::table('product_schema_versions')->where('id', $versionId)->value('product_schema_id');

        return is_string($schemaId) ? $schemaId : null;
    }

    /**
     * @return array{id: string, key: string, label: string, comparison_group: string, default_template_key: string, version: int, schema_version: int, assigned_product_count: int, attributes: list<array{id: string, key: string, type: string, unit: string|null, required: bool, comparable: bool, filterable: bool, label: string, options: list<string>}>}
     */
    private function schemaSnapshot(mixed $row): array
    {
        $data = is_object($row) ? get_object_vars($row) : [];
        $versionId = is_string($data['current_version_id'] ?? null) ? $data['current_version_id'] : '';
        $schemaVersion = 1;
        if ($versionId !== '') {
            $schemaVersion = (int) DB::table('product_schema_versions')->where('id', $versionId)->value('version');
        }

        $schemaId = is_string($data['id'] ?? null) ? $data['id'] : '';
        $versionIds = $schemaId === ''
            ? collect()
            : DB::table('product_schema_versions')->where('product_schema_id', $schemaId)->pluck('id');
        $assigned = $versionIds->isEmpty()
            ? 0
            : (int) DB::table('products')->whereIn('schema_version_id', $versionIds)->count();

        return [
            'id' => $schemaId,
            'key' => is_string($data['semantic_key'] ?? null) ? $data['semantic_key'] : '',
            'label' => is_string($data['label'] ?? null) ? $data['label'] : '',
            'comparison_group' => is_string($data['comparison_group'] ?? null) ? $data['comparison_group'] : '',
            'default_template_key' => is_string($data['default_template_key'] ?? null) ? $data['default_template_key'] : '',
            'version' => (int) ($data['version'] ?? 1),
            'schema_version' => $schemaVersion,
            'assigned_product_count' => $assigned,
            'attributes' => $versionId === '' ? [] : app(ProductAttributes::class)->fieldsForVersion($versionId),
        ];
    }
}
