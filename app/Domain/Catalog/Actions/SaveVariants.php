<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Support\StoreCurrency;
use App\Domain\Catalog\Support\VariantOptions;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\ShoppingTools\Actions\EvaluateWatchEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SaveVariants
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, version: int, variant_count: int}
     */
    public function save(StaffAccount $actor, array $input): array
    {
        if (! $actor->hasPermission('catalog.write')) {
            throw new AuthorizationException;
        }
        $money = app(StoreCurrency::class)->current();
        $data = Validator::make(['variants' => $input], [
            'variants' => ['required', 'array:id,version,options,variants'],
            'variants.id' => ['required', 'uuid'],
            'variants.version' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'variants.options' => ['present', 'array', 'list', 'max:3'],
            'variants.options.*' => ['required', 'array:name,values'],
            'variants.options.*.name' => ['required', 'string', 'max:40', 'distinct:ignore_case', 'regex:/^[A-Za-z][A-Za-z0-9 ]{0,39}$/'],
            'variants.options.*.values' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'variants.options.*.values.*' => ['required', 'string', 'max:40', 'distinct:ignore_case', 'regex:/^[A-Za-z0-9][A-Za-z0-9.+\-\/ ]{0,39}$/'],
            'variants.variants' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'variants.variants.*' => ['required', 'array'],
            'variants.variants.*.id' => ['nullable', 'uuid', 'distinct:strict'],
            'variants.variants.*.sku' => ['required', 'string', 'max:64', 'distinct:ignore_case', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/'],
            'variants.variants.*.price_amount' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'variants.variants.*.is_default' => ['required', 'boolean'],
            'variants.variants.*.available' => ['required', 'boolean'],
            'variants.variants.*.options' => ['present', 'array'],
            'variants.variants.*.image_asset_id' => ['nullable', 'uuid'],
            'variants.variants.*.image_alt' => ['required_with:variants.variants.*.image_asset_id', 'nullable', 'string', 'max:200'],
            'variants.variants.*.weight_grams' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'variants.variants.*.length_mm' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'variants.variants.*.width_mm' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'variants.variants.*.height_mm' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ])->validate()['variants'];
        $defaults = array_values(array_filter($data['variants'], fn (array $variant): bool => $variant['is_default'] === true));
        if (count($defaults) !== 1) {
            throw ValidationException::withMessages(['variants' => 'Choose exactly one default variant.']);
        }
        if ($defaults[0]['available'] !== true) {
            throw ValidationException::withMessages(['variants' => 'The default variant must be available.']);
        }
        if ($data['options'] === []) {
            if (count($data['variants']) !== 1 || $data['variants'][0]['options'] !== []) {
                throw ValidationException::withMessages(['options' => 'A product without options can have only one standard variant.']);
            }
        }
        $axisNames = [];
        $axisValues = [];
        foreach ($data['options'] as $axis) {
            $axisNames[] = $axis['name'];
            $axisValues[$axis['name']] = $axis['values'];
        }
        $fingerprints = [];
        foreach ($data['variants'] as $index => $variant) {
            $this->assertOptionCombination($variant['options'], $axisNames, $axisValues, $index);
            $fingerprint = VariantOptions::fingerprint($variant['options']);
            if (isset($fingerprints[$fingerprint])) {
                throw ValidationException::withMessages(['variants.'.$index.'.options' => 'Each option combination must be unique.']);
            }
            $fingerprints[$fingerprint] = true;
            $this->assertPhysicalDimensions($variant, $index);
        }
        try {
            return DB::transaction(function () use ($actor, $data, $money, $axisNames): array {
                $now = now();
                $existing = DB::table('products')->where('id', $data['id'])->lockForUpdate()->first();
                abort_unless($existing !== null, 404);
                abort_unless((int) $existing->version === (int) $data['version'], 409, 'This product changed. Reload before saving.');
                abort_if((int) $existing->version >= 4294967295, 409);
                $digital = $existing->type === 'digital';
                if ($digital) {
                    foreach ($data['variants'] as $index => $variant) {
                        if (isset($variant['weight_grams']) || isset($variant['length_mm']) || isset($variant['width_mm']) || isset($variant['height_mm'])) {
                            throw ValidationException::withMessages(['variants.'.$index.'.weight_grams' => 'Digital variants cannot have physical dimensions or weight.']);
                        }
                    }
                }
                $keepIds = [];
                foreach ($data['variants'] as $variant) {
                    if (isset($variant['id'])) {
                        $owned = DB::table('product_variants')->where('id', $variant['id'])->where('product_id', $data['id'])->lockForUpdate()->exists();
                        if (! $owned) {
                            throw ValidationException::withMessages(['variants' => 'A variant belongs to a different product.']);
                        }
                        $keepIds[] = $variant['id'];
                    }
                }
                $existingIds = [];
                foreach (DB::table('product_variants')->where('product_id', $data['id'])->lockForUpdate()->pluck('id') as $existingId) {
                    if (is_string($existingId)) {
                        $existingIds[] = $existingId;
                    }
                }
                // Pre-save listed flags, captured before the rewrite
                // below: SHOP-04 treats a variant re-listing as a
                // publication change for watch alerts.
                $listedBefore = [];
                foreach (DB::table('product_variants')->where('product_id', $data['id'])->select(['id', 'available'])->get() as $row) {
                    $listedBefore[(string) $row->id] = (bool) $row->available;
                }
                $removeIds = array_values(array_diff($existingIds, $keepIds));
                if ($removeIds !== []) {
                    if (DB::table('inventory_balances')->whereIn('variant_id', $removeIds)->where('reserved', '>', 0)->exists()) {
                        throw ValidationException::withMessages(['variants' => 'Release reserved stock before replacing this variant.']);
                    }
                    DB::table('inventory_balances')->whereIn('variant_id', $removeIds)->delete();
                }
                $delete = DB::table('product_variants')->where('product_id', $data['id']);
                if ($keepIds !== []) {
                    $delete->whereNotIn('id', $keepIds);
                }
                $delete->delete();
                DB::table('product_option_axes')->where('product_id', $data['id'])->delete();
                foreach ($data['options'] as $order => $axis) {
                    $axisId = (string) Str::uuid();
                    DB::table('product_option_axes')->insert([
                        'id' => $axisId, 'product_id' => $data['id'], 'name' => $axis['name'], 'sort_order' => $order,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    foreach ($axis['values'] as $valueOrder => $value) {
                        DB::table('product_option_values')->insert([
                            'id' => (string) Str::uuid(), 'axis_id' => $axisId, 'value' => $value, 'sort_order' => $valueOrder,
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }
                foreach ($data['variants'] as $variant) {
                    $media = $this->readyImage($variant['image_asset_id'] ?? null);
                    $id = isset($variant['id']) ? (string) $variant['id'] : (string) Str::uuid();
                    $options = [];
                    foreach ($axisNames as $name) {
                        $options[] = ['name' => $name, 'value' => $variant['options'][$name]];
                    }
                    $row = [
                        'product_id' => $data['id'],
                        'sku' => $variant['sku'],
                        'is_default' => $variant['is_default'],
                        'available' => $variant['available'],
                        'option_fingerprint' => VariantOptions::fingerprint($variant['options']),
                        'options' => json_encode($options, JSON_THROW_ON_ERROR),
                        'price_amount' => $variant['price_amount'],
                        'currency' => $money['currency'],
                        'exponent' => $money['exponent'],
                        'tax_class_key' => 'standard',
                        'shipping_class_key' => 'general',
                        'media_asset_id' => $media['id'],
                        'image_alt' => $media['id'] === null ? null : (is_string($variant['image_alt'] ?? null) ? $variant['image_alt'] : 'Product variant'),
                        'weight_grams' => $digital ? null : ($variant['weight_grams'] ?? null),
                        'length_mm' => $digital ? null : ($variant['length_mm'] ?? null),
                        'width_mm' => $digital ? null : ($variant['width_mm'] ?? null),
                        'height_mm' => $digital ? null : ($variant['height_mm'] ?? null),
                        'max_quantity' => $digital ? 1 : 99,
                        'tracks_inventory' => ! $digital && isset($variant['id']) && (bool) DB::table('product_variants')->where('id', $id)->value('tracks_inventory'),
                        'updated_at' => $now,
                    ];
                    if (isset($variant['id'])) {
                        DB::table('product_variants')->where('id', $id)->update($row);
                    } else {
                        DB::table('product_variants')->insert($row + ['id' => $id, 'created_at' => $now]);
                    }
                }
                $version = ((int) $existing->version) + 1;
                DB::table('products')->where('id', $data['id'])->update(['version' => $version, 'updated_at' => $now]);
                // SHOP-04 watch alerts: every save is a price source
                // (the baseline comparison decides), and a variant
                // whose listed flag flips on is a publication source.
                $watch = app(EvaluateWatchEvent::class);
                $watchEventKey = 'variants:'.$data['id'].':'.$version;
                foreach ($data['variants'] as $variant) {
                    if (! isset($variant['id'])) {
                        continue;
                    }
                    $watchVariantId = (string) $variant['id'];
                    if (! array_key_exists($watchVariantId, $listedBefore)) {
                        continue;
                    }
                    $watch->price($watchVariantId, $watchEventKey);
                    if ($listedBefore[$watchVariantId] === false && $variant['available'] === true) {
                        $watch->stock($watchVariantId, $watchEventKey);
                    }
                }
                app(RecordEvent::class)->record('platform.audit.recorded', 'catalog:product:'.$data['id'].':'.$version, [
                    'action' => 'catalog.variants.saved', 'subject_type' => 'product', 'subject_id' => $data['id'],
                    'actor_type' => 'staff', 'actor_id' => $actor->id, 'metadata' => ['version' => $version, 'variant_count' => count($data['variants'])],
                ]);

                return ['id' => $data['id'], 'version' => $version, 'variant_count' => count($data['variants'])];
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['variants' => 'Each SKU and option combination must be unique.']);
        } catch (HttpException $exception) {
            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $axisNames
     * @param  array<string, list<string>>  $axisValues
     */
    private function assertOptionCombination(array $options, array $axisNames, array $axisValues, int $index): void
    {
        if (count($options) !== count($axisNames)) {
            throw ValidationException::withMessages(['variants.'.$index.'.options' => 'Each variant must include every option name.']);
        }
        foreach ($axisNames as $name) {
            if (! array_key_exists($name, $options)) {
                throw ValidationException::withMessages(['variants.'.$index.'.options' => 'Each variant must include every option name.']);
            }
            $value = $options[$name];
            if (! is_string($value) || ! in_array($value, $axisValues[$name], true)) {
                throw ValidationException::withMessages(['variants.'.$index.'.options' => 'Choose an option value that exists.']);
            }
        }
    }

    /** @param array<string, mixed> $variant */
    private function assertPhysicalDimensions(array $variant, int $index): void
    {
        $length = array_key_exists('length_mm', $variant) ? $variant['length_mm'] : null;
        $width = array_key_exists('width_mm', $variant) ? $variant['width_mm'] : null;
        $height = array_key_exists('height_mm', $variant) ? $variant['height_mm'] : null;
        $present = is_int($length) + is_int($width) + is_int($height);
        if ($present > 0 && $present < 3) {
            throw ValidationException::withMessages(['variants.'.$index.'.length_mm' => 'Provide complete physical dimensions.']);
        }
    }

    /** @return array{id: string|null, alt: string|null} */
    private function readyImage(mixed $assetId): array
    {
        if (! is_string($assetId) || $assetId === '') {
            return ['id' => null, 'alt' => null];
        }
        $asset = DB::table('media_assets')->where('id', $assetId)->lockForUpdate()->first();
        if ($asset === null || $asset->kind !== 'public_image' || $asset->status !== 'ready'
            || ! is_string($asset->public_key) || ! preg_match('#^media/[0-9a-f-]{36}/[a-f0-9]{64}\.png$#D', $asset->public_key)) {
            throw ValidationException::withMessages(['variants' => 'Attach a processed product image.']);
        }

        return ['id' => $assetId, 'alt' => null];
    }
}
