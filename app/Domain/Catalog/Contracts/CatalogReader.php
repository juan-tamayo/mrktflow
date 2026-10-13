<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Contracts;

interface CatalogReader
{
    /**
     * Public catalog lookup. Drafts are unpublished, never leaked as found.
     *
     * @return array{id: string, slug: string, title: string, description: string, type: string, status: string, image: array{url: string, alt: string}, variant: array{id: string, sku: string, isDefault: bool, money: array{amount: int, currency: string, exponent: int}}, breadcrumbs: list<array{title: string, url: string}>}
     */
    public function publicProduct(string $idOrSlug): array;

    /**
     * Staff catalog lookup. Drafts are visible to authorized callers only.
     *
     * @return array{id: string, slug: string, title: string, description: string, type: string, status: string, version: int, image: array{url: string, alt: string, assetId: string}, variant: array{id: string, sku: string, isDefault: bool, money: array{amount: int, currency: string, exponent: int}}, category_ids: list<string>}
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

    public function publishedCount(): int;
}
