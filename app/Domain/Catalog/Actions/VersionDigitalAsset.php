<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Support\CatalogMediaUploadAuthorization;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\RecordEvent;
use App\Domain\Platform\Actions\StoreMedia;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class VersionDigitalAsset
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{id: string, asset_id: string, label: string, version: int, status: string, bytes: int, content_type: string, checksum: string}
     */
    public function version(StaffAccount $actor, array $input): array
    {
        $this->assertWriter($actor);
        $data = Validator::make($input, [
            'product_id' => ['required', 'uuid'],
            'digital_asset_id' => ['nullable', 'uuid'],
            'label' => ['required', 'string', 'max:120'],
            'file' => ['required'],
        ])->validate();
        $file = $input['file'] ?? null;
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Upload a private digital file.']);
        }
        $product = DB::table('products')->where('id', $data['product_id'])->first();
        if ($product === null) {
            abort(404);
        }
        if ($product->type !== 'digital') {
            throw ValidationException::withMessages(['product_id' => 'Private files can only be attached to digital products.']);
        }
        $mediaId = app(StoreMedia::class)->store($file, 'private_file', new CatalogMediaUploadAuthorization($actor));

        return DB::transaction(function () use ($actor, $data, $mediaId): array {
            $now = now();
            $product = DB::table('products')->where('id', $data['product_id'])->lockForUpdate()->first();
            abort_unless($product !== null && $product->type === 'digital', 404);
            $media = DB::table('media_assets')->where('id', $mediaId)->lockForUpdate()->first();
            abort_unless($media !== null && $media->kind === 'private_file', 404);
            $assetId = is_string($data['digital_asset_id'] ?? null) ? $data['digital_asset_id'] : (string) Str::uuid();
            $asset = DB::table('digital_assets')->where('id', $assetId)->lockForUpdate()->first();
            if (isset($data['digital_asset_id'])) {
                if ($asset === null || $asset->product_id !== $data['product_id']) {
                    throw ValidationException::withMessages(['digital_asset_id' => 'Choose a digital file that belongs to this product.']);
                }
                DB::table('digital_assets')->where('id', $assetId)->update(['label' => $data['label'], 'updated_at' => $now]);
            } else {
                DB::table('digital_assets')->insert([
                    'id' => $assetId, 'product_id' => $data['product_id'], 'label' => $data['label'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $next = (int) DB::table('digital_asset_versions')->where('digital_asset_id', $assetId)->max('version');
            $versionId = (string) Str::uuid();
            $status = $this->statusFromMedia($media);
            DB::table('digital_asset_versions')->insert([
                'id' => $versionId,
                'digital_asset_id' => $assetId,
                'media_asset_id' => $mediaId,
                'version' => $next + 1,
                'status' => $status,
                'bytes' => (int) $media->bytes,
                'content_type' => (string) $media->content_type,
                'checksum' => (string) $media->checksum,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            app(RecordEvent::class)->record('platform.audit.recorded', 'catalog:digital:'.$versionId, [
                'action' => 'catalog.digital_asset.versioned', 'subject_type' => 'digital_asset', 'subject_id' => $assetId,
                'actor_type' => 'staff', 'actor_id' => $actor->id, 'metadata' => ['version' => $next + 1],
            ]);

            return [
                'id' => $versionId,
                'asset_id' => $assetId,
                'label' => $data['label'],
                'version' => $next + 1,
                'status' => $status,
                'bytes' => (int) $media->bytes,
                'content_type' => (string) $media->content_type,
                'checksum' => (string) $media->checksum,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{product_id: string, variant_id: string, version_ids: list<string>}
     */
    public function attach(StaffAccount $actor, array $input): array
    {
        $this->assertWriter($actor);
        $data = Validator::make($input, [
            'product_id' => ['required', 'uuid'],
            'variant_id' => ['required', 'uuid'],
            'version_ids' => ['present', 'array', 'list', 'max:20'],
            'version_ids.*' => ['required', 'uuid', 'distinct:strict'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data): array {
            $now = now();
            $product = DB::table('products')->where('id', $data['product_id'])->lockForUpdate()->first();
            abort_unless($product !== null, 404);
            if ($product->type !== 'digital') {
                throw ValidationException::withMessages(['product_id' => 'Private files can only be attached to digital products.']);
            }
            $variant = DB::table('product_variants')->where('id', $data['variant_id'])->where('product_id', $data['product_id'])->lockForUpdate()->first();
            abort_unless($variant !== null, 404);
            $seenAssets = [];
            foreach ($data['version_ids'] as $index => $versionId) {
                $version = DB::table('digital_asset_versions')->where('id', $versionId)->lockForUpdate()->first();
                if ($version === null) {
                    throw ValidationException::withMessages(['version_ids.'.$index => 'Choose a digital file version that exists.']);
                }
                $asset = DB::table('digital_assets')->where('id', $version->digital_asset_id)->lockForUpdate()->first();
                if ($asset === null || $asset->product_id !== $data['product_id']) {
                    throw ValidationException::withMessages(['version_ids.'.$index => 'Choose a digital file that belongs to this product.']);
                }
                if ((string) $version->status !== 'ready') {
                    throw ValidationException::withMessages(['version_ids.'.$index => 'Attach only ready digital file versions.']);
                }
                $assetId = (string) $asset->id;
                if (isset($seenAssets[$assetId])) {
                    throw ValidationException::withMessages(['version_ids.'.$index => 'Each file can be attached once.']);
                }
                $seenAssets[$assetId] = true;
            }
            DB::table('variant_digital_assets')->where('variant_id', $data['variant_id'])->delete();
            foreach ($data['version_ids'] as $order => $versionId) {
                $version = DB::table('digital_asset_versions')->where('id', $versionId)->first();
                abort_unless($version !== null, 404);
                DB::table('variant_digital_assets')->insert([
                    'id' => (string) Str::uuid(),
                    'variant_id' => $data['variant_id'],
                    'digital_asset_id' => $version->digital_asset_id,
                    'digital_asset_version_id' => $versionId,
                    'sort_order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            app(RecordEvent::class)->record('platform.audit.recorded', 'catalog:digital-attach:'.$data['variant_id'].':'.$now->timestamp, [
                'action' => 'catalog.digital_asset.attached', 'subject_type' => 'product', 'subject_id' => $data['product_id'],
                'actor_type' => 'staff', 'actor_id' => $actor->id, 'metadata' => ['variant_id' => $data['variant_id']],
            ]);

            return ['product_id' => $data['product_id'], 'variant_id' => $data['variant_id'], 'version_ids' => $data['version_ids']];
        });
    }

    private function assertWriter(StaffAccount $actor): void
    {
        if (! $actor->hasPermission('catalog.write') || ! $actor->hasPermission('media.upload')) {
            throw new AuthorizationException;
        }
    }

    private function statusFromMedia(object $media): string
    {
        $status = is_string($media->status ?? null) ? $media->status : '';
        $scan = is_string($media->scan_status ?? null) ? $media->scan_status : '';
        if ($status === 'failed') {
            return 'failed';
        }
        if ($status !== 'ready') {
            return 'processing';
        }
        if ($scan === 'quarantined') {
            return 'quarantined';
        }

        return 'ready';
    }
}
