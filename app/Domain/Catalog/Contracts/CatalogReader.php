<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Contracts;

interface CatalogReader
{
    /**
     * Public catalog lookup. Drafts are unpublished, never leaked as found.
     *
     * @return array{id: string, slug: string, title: string, description: string, type: string, status: string, image: array{url: string, alt: string}, variant: array{id: string, sku: string, isDefault: bool, money: array{amount: int, currency: string, exponent: int}}, variants: list<array{id: string, sku: string, label: string, isDefault: bool, available: bool, options: list<array{name: string, value: string}>, money: array{amount: int, currency: string, exponent: int}, image: array{url: string, alt: string}, weight_grams: int|null, length_mm: int|null, width_mm: int|null, height_mm: int|null}>, option_axes: list<array{name: string, values: list<string>}>, breadcrumbs: list<array{title: string, url: string}>, schema: array{id: string, key: string, comparison_group: string}|null, typed_attributes: list<array{label: string, value: string}>}
     */
    public function publicProduct(string $idOrSlug): array;

    /**
     * Staff catalog lookup. Drafts are visible to authorized callers only.
     *
     * @return array{id: string, slug: string, title: string, description: string, type: string, status: string, version: int, image: array{url: string, alt: string, assetId: string}, variant: array{id: string, sku: string, isDefault: bool, money: array{amount: int, currency: string, exponent: int}}, variants: list<array{id: string, sku: string, label: string, isDefault: bool, available: bool, options: list<array{name: string, value: string}>, money: array{amount: int, currency: string, exponent: int}, image: array{url: string, alt: string, assetId?: string}, weight_grams: int|null, length_mm: int|null, width_mm: int|null, height_mm: int|null}>, option_axes: list<array{name: string, values: list<string>}>, category_ids: list<string>, schema_id: string|null, attributes: array<string, string|bool>}
     */
    public function staffProduct(string $idOrSlug): array;

    /**
     * @return array{id: string, path: string, title: string, description: string, status: string, breadcrumbs: list<array{title: string, url: string}>, products: list<array{id: string, slug: string, title: string, image: array{url: string, alt: string}, money: array{amount: int, currency: string, exponent: int}}>}
     */
    public function publicCategory(string $path): array;

    /**
     * @return array{id: string, parent_id: string|null, title: string, slug: string, path: string, description: string, status: string, version: int}
     */
    public function staffCategory(string $idOrPath): array;

    /**
     * @return list<array{id: string, parent_id: string|null, title: string, slug: string, path: string, status: string, version: int}>
     */
    public function staffCategories(): array;

    /**
     * @return array{id: string, key: string, label: string, comparison_group: string, default_template_key: string, version: int, schema_version: int, assigned_product_count: int, attributes: list<array{id: string, key: string, type: string, unit: string|null, required: bool, comparable: bool, filterable: bool, label: string, options: list<string>}>}
     */
    public function staffSchema(string $id): array;

    /**
     * @return list<array{id: string, key: string, label: string, comparison_group: string, default_template_key: string, version: int, schema_version: int, assigned_product_count: int, attributes: list<array{id: string, key: string, type: string, unit: string|null, required: bool, comparable: bool, filterable: bool, label: string, options: list<string>}>}>
     */
    public function staffSchemas(): array;

    public function publishedCount(): int;
}
