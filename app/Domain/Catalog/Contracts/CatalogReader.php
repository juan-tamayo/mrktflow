<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Contracts;

interface CatalogReader
{
    /**
     * Public catalog lookup. Drafts are unpublished, never leaked as found.
     *
     * @return array{id: string, slug: string, title: string, description: string, type: string, status: string, image: array{url: string, alt: string}, variant: array{id: string, sku: string, isDefault: bool, money: array{amount: int, currency: string, exponent: int}}}
     */
    public function publicProduct(string $idOrSlug): array;

    /**
     * Staff catalog lookup. Drafts are visible to authorized callers only.
     *
     * @return array{id: string, slug: string, title: string, description: string, type: string, status: string, version: int, image: array{url: string, alt: string, assetId: string}, variant: array{id: string, sku: string, isDefault: bool, money: array{amount: int, currency: string, exponent: int}}}
     */
    public function staffProduct(string $idOrSlug): array;

    public function publishedCount(): int;
}
