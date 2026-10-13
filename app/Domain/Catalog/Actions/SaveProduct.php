<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Support\ProductAttributes;
use App\Domain\Catalog\Support\ReservedCatalogSlugs;
use App\Domain\Catalog\Support\StoreCurrency;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SaveProduct
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, slug: string, version: int, status: string, type: string}
     */
    public function save(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('catalog.write')) {
            throw new AuthorizationException;
        }
        $money = app(StoreCurrency::class)->current();
        $data = Validator::make(['product' => $input], [
            'product' => ['required', 'array:id,version,title,slug,description,status,sku,price_amount,image_asset_id,image_alt,category_ids,schema_id,attributes'],
            'product.id' => ['nullable', 'uuid'],
            'product.version' => ['required_with:product.id', 'integer', 'min:1', 'max:4294967295'],
            'product.title' => ['required', 'string', 'max:200'],
            'product.slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(ReservedCatalogSlugs::all())],
            'product.description' => ['required', 'string', 'max:5000'],
            'product.status' => ['required', Rule::in(['draft', 'published'])],
            'product.sku' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/'],
            'product.price_amount' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'product.image_asset_id' => ['required', 'uuid'],
            'product.image_alt' => ['required', 'string', 'max:200'],
            'product.category_ids' => ['sometimes', 'array', 'max:20'],
            'product.category_ids.*' => ['uuid', 'distinct:strict'],
            'product.schema_id' => ['nullable', 'uuid'],
            'product.attributes' => ['sometimes', 'array', 'max:30'],
        ])->validate()['product'];
        try {
            return DB::transaction(function () use ($actor, $data, $money): array {
                $now = now();
                $existing = null;
                if (isset($data['id'])) {
                    $existing = DB::table('products')->where('id', $data['id'])->lockForUpdate()->first();
                    abort_unless($existing !== null, 404);
                    abort_unless((int) $existing->version === (int) $data['version'], 409, 'This product changed. Reload before saving.');
                    abort_if((int) $existing->version >= 4294967295, 409);
                }
                $asset = DB::table('media_assets')->where('id', $data['image_asset_id'])->lockForUpdate()->first();
                if ($asset === null || $asset->kind !== 'public_image' || $asset->status !== 'ready'
                    || ! is_string($asset->public_key) || ! preg_match('#^media/[0-9a-f-]{36}/[a-f0-9]{64}\.png$#D', $asset->public_key)) {
                    throw ValidationException::withMessages(['product.image_asset_id' => 'Attach a processed product image.']);
                }
                $id = isset($data['id']) ? (string) $data['id'] : (string) Str::uuid();
                $version = $existing === null ? 1 : ((int) $existing->version) + 1;
                $publishedAt = $data['status'] === 'published'
                    ? ($existing !== null && $existing->status === 'published' ? $existing->published_at : $now)
                    : null;
                $product = [
                    'title' => $data['title'], 'slug' => $data['slug'], 'description' => $data['description'],
                    'type' => 'physical', 'status' => $data['status'], 'version' => $version,
                    'published_at' => $publishedAt, 'updated_at' => $now,
                ];
                if ($existing === null) {
                    DB::table('products')->insert($product + ['id' => $id, 'created_at' => $now]);
                    $variantId = (string) Str::uuid();
                    DB::table('product_variants')->insert([
                        'id' => $variantId, 'product_id' => $id, 'sku' => $data['sku'], 'is_default' => true,
                        'option_fingerprint' => '', 'price_amount' => $data['price_amount'],
                        'currency' => $money['currency'], 'exponent' => $money['exponent'],
                        'tax_class_key' => 'standard', 'shipping_class_key' => 'general',
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    DB::table('product_media')->insert([
                        'id' => (string) Str::uuid(), 'product_id' => $id, 'media_asset_id' => $data['image_asset_id'],
                        'alt' => $data['image_alt'], 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                } else {
                    DB::table('products')->where('id', $id)->update($product);
                    DB::table('product_variants')->where('product_id', $id)->where('is_default', true)->update([
                        'sku' => $data['sku'], 'price_amount' => $data['price_amount'],
                        'currency' => $money['currency'], 'exponent' => $money['exponent'], 'updated_at' => $now,
                    ]);
                    $media = DB::table('product_media')->where('product_id', $id)->where('sort_order', 0)->lockForUpdate()->first();
                    if ($media === null) {
                        throw new InvalidArgumentException('Simple products require a default image.');
                    }
                    DB::table('product_media')->where('id', $media->id)->update([
                        'media_asset_id' => $data['image_asset_id'], 'alt' => $data['image_alt'], 'updated_at' => $now,
                    ]);
                }
                if (array_key_exists('category_ids', $data)) {
                    $this->syncCategories($id, $data['category_ids'], $now);
                }
                if (array_key_exists('schema_id', $data) || array_key_exists('attributes', $data)) {
                    $this->syncSchema($id, is_string($data['schema_id'] ?? null) ? $data['schema_id'] : null, is_array($data['attributes'] ?? null) ? $data['attributes'] : [], $now);
                }
                $action = $data['status'] === 'published' ? 'catalog.product.published' : 'catalog.product.saved';
                app(RecordEvent::class)->record('platform.audit.recorded', 'catalog:product:'.$id.':'.$version, [
                    'action' => $action, 'subject_type' => 'product', 'subject_id' => $id,
                    'actor_type' => 'staff', 'actor_id' => $actor->id, 'metadata' => ['version' => $version],
                ]);

                return ['id' => $id, 'slug' => $data['slug'], 'version' => $version, 'status' => $data['status'], 'type' => 'physical'];
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (DB::table('products')->where('slug', $data['slug'])->when(isset($data['id']), fn ($query) => $query->where('id', '!=', $data['id']))->exists()) {
                throw ValidationException::withMessages(['product.slug' => 'This slug is already used.']);
            }
            if (DB::table('product_variants')->where('sku', $data['sku'])->when(isset($data['id']), function ($query) use ($data): void {
                $query->where('product_id', '!=', $data['id']);
            })->exists()) {
                throw ValidationException::withMessages(['product.sku' => 'This SKU is already used.']);
            }
            throw $exception;
        }
    }

    /** @param list<string> $categoryIds */
    private function syncCategories(string $productId, array $categoryIds, mixed $now): void
    {
        $unique = array_values(array_unique($categoryIds));
        if ($unique !== []) {
            $found = DB::table('categories')->whereIn('id', $unique)->lockForUpdate()->pluck('id')->all();
            if (count($found) !== count($unique)) {
                throw ValidationException::withMessages(['product.category_ids' => 'Choose categories that exist.']);
            }
        }
        DB::table('product_categories')->where('product_id', $productId)->delete();
        foreach ($unique as $categoryId) {
            DB::table('product_categories')->insert([
                'id' => (string) Str::uuid(),
                'product_id' => $productId,
                'category_id' => $categoryId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function syncSchema(string $productId, ?string $schemaId, array $attributes, mixed $now): void
    {
        $values = app(ProductAttributes::class);
        if ($schemaId === null || $schemaId === '') {
            DB::table('products')->where('id', $productId)->update(['schema_version_id' => null, 'updated_at' => $now]);
            $values->clearValues($productId);

            return;
        }
        $schema = DB::table('product_schemas')->where('id', $schemaId)->lockForUpdate()->first();
        if ($schema === null || ! is_string($schema->current_version_id)) {
            throw ValidationException::withMessages(['product.schema_id' => 'Choose a product schema that exists.']);
        }
        $values->replaceValues($productId, $schema->current_version_id, $attributes, $now);
        DB::table('products')->where('id', $productId)->update(['schema_version_id' => $schema->current_version_id, 'updated_at' => $now]);
    }
}
