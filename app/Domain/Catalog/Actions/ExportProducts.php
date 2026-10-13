<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Support\ProductImportCsv;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ExportProducts
{
    public function export(StaffAccount $actor): string
    {
        if (! $actor->hasPermission('catalog.write')) {
            throw new AuthorizationException;
        }
        $headers = [
            'sku', 'title', 'slug', 'description', 'status', 'type', 'price_amount',
            'image_asset_id', 'image_alt', 'category_paths', 'schema_key',
        ];
        $lines = [ProductImportCsv::line($headers)];
        $records = DB::table('product_variants')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('product_media', function ($join): void {
                $join->on('products.id', '=', 'product_media.product_id')->where('product_media.sort_order', '=', 0);
            })
            ->leftJoin('product_schema_versions', 'products.schema_version_id', '=', 'product_schema_versions.id')
            ->leftJoin('product_schemas', 'product_schema_versions.product_schema_id', '=', 'product_schemas.id')
            ->orderBy('product_variants.sku')
            ->orderBy('products.id')
            ->select([
                'product_variants.sku',
                'products.id as product_id',
                'products.title',
                'products.slug',
                'products.description',
                'products.status',
                'products.type',
                'product_variants.price_amount',
                'product_media.media_asset_id',
                'product_media.alt',
                'product_schemas.semantic_key',
            ])
            ->get();
        foreach ($records as $record) {
            $lines[] = ProductImportCsv::line([
                ProductImportCsv::neutralize((string) $record->sku),
                ProductImportCsv::neutralize((string) $record->title),
                ProductImportCsv::neutralize((string) $record->slug),
                ProductImportCsv::neutralize((string) $record->description),
                ProductImportCsv::neutralize((string) $record->status),
                ProductImportCsv::neutralize((string) $record->type),
                ProductImportCsv::neutralize((string) $record->price_amount),
                ProductImportCsv::neutralize(is_string($record->media_asset_id) ? $record->media_asset_id : ''),
                ProductImportCsv::neutralize(is_string($record->alt) ? $record->alt : ''),
                ProductImportCsv::neutralize($this->categoryPaths((string) $record->product_id)),
                ProductImportCsv::neutralize(is_string($record->semantic_key) ? $record->semantic_key : ''),
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    private function categoryPaths(string $productId): string
    {
        $paths = DB::table('product_categories')
            ->join('categories', 'product_categories.category_id', '=', 'categories.id')
            ->where('product_categories.product_id', $productId)
            ->orderBy('categories.path')
            ->pluck('categories.path')
            ->all();
        $clean = [];
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $clean[] = $path;
            }
        }

        return implode('|', $clean);
    }
}
