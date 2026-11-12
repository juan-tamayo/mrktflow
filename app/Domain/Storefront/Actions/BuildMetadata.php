<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Actions;

use App\Domain\ThemesContent\Data\PageMetadata;

/**
 * Canonical, Open Graph and structured data for live public content (WEB-05).
 *
 * Ratings and reviews are omitted unless a later module supplies real facts.
 */
final class BuildMetadata
{
    /**
     * @param  array{slug: string, title: string, description: string, image: array{url: string, alt: string}}  $snapshot
     * @param  list<array{title: string, url: string}>  $breadcrumbs
     * @param  array{amount: int, currency: string, exponent: int, availability: string, url?: string}  $offer
     */
    public function product(array $snapshot, array $breadcrumbs, array $offer): PageMetadata
    {
        $canonical = $this->absolute('/products/'.$snapshot['slug']);
        $image = $this->absolute($snapshot['image']['url']);
        $availability = match ($offer['availability']) {
            'in_stock' => 'https://schema.org/InStock',
            default => 'https://schema.org/OutOfStock',
        };
        $graph = [[
            '@type' => 'Product',
            'name' => $snapshot['title'],
            'description' => $snapshot['description'],
            'image' => $image,
            'url' => $canonical,
            'offers' => [
                '@type' => 'Offer',
                'url' => $this->absolute($offer['url'] ?? '/products/'.$snapshot['slug']),
                'priceCurrency' => $offer['currency'],
                'price' => $this->decimal($offer['amount'], $offer['exponent']),
                'availability' => $availability,
            ],
        ]];
        $breadcrumb = $this->breadcrumbs($breadcrumbs, $snapshot['title'], $canonical);
        if ($breadcrumb !== null) {
            $graph[] = $breadcrumb;
        }

        return new PageMetadata(
            canonical: $canonical,
            title: $snapshot['title'],
            description: $snapshot['description'],
            robots: 'index, follow',
            ogType: 'product',
            ogImage: $image,
            graph: $graph,
        );
    }

    /**
     * @param  array{path: string, title: string, description: string}  $snapshot
     * @param  list<array{title: string, url: string}>  $breadcrumbs
     */
    public function category(array $snapshot, array $breadcrumbs): PageMetadata
    {
        $canonical = $this->absolute('/categories/'.$snapshot['path']);
        $graph = [[
            '@type' => 'CollectionPage',
            'name' => $snapshot['title'],
            'description' => $snapshot['description'],
            'url' => $canonical,
        ]];
        $breadcrumb = $this->breadcrumbs($breadcrumbs, $snapshot['title'], $canonical);
        if ($breadcrumb !== null) {
            $graph[] = $breadcrumb;
        }

        return new PageMetadata(
            canonical: $canonical,
            title: $snapshot['title'],
            description: $snapshot['description'],
            robots: 'index, follow',
            ogType: 'website',
            ogImage: null,
            graph: $graph,
        );
    }

    public function document(string $kind, string $path, string $title, string $description): PageMetadata
    {
        $canonical = $this->absolute('/'.$path);
        $type = $kind === 'article' ? 'Article' : 'WebPage';

        return new PageMetadata(
            canonical: $canonical,
            title: $title,
            description: $description,
            robots: 'index, follow',
            ogType: $kind === 'article' ? 'article' : 'website',
            ogImage: null,
            graph: [[
                '@type' => $type,
                'headline' => $title,
                'name' => $title,
                'description' => $description,
                'url' => $canonical,
            ]],
        );
    }

    /**
     * @param  list<array{title: string, url: string}>  $crumbs
     * @return array<string, mixed>|null
     */
    private function breadcrumbs(array $crumbs, string $currentTitle, string $currentUrl): ?array
    {
        if ($crumbs === []) {
            return null;
        }
        $items = [];
        $position = 1;
        foreach ($crumbs as $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $crumb['title'],
                'item' => $this->absolute($crumb['url']),
            ];
            $position++;
        }
        $items[] = [
            '@type' => 'ListItem',
            'position' => $position,
            'name' => $currentTitle,
            'item' => $currentUrl,
        ];

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    private function absolute(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim(config()->string('app.url'), '/').$path;
    }

    private function decimal(int $amount, int $exponent): string
    {
        if ($exponent === 0) {
            return (string) $amount;
        }
        $scale = 10 ** $exponent;

        return intdiv($amount, $scale).'.'.str_pad((string) ($amount % $scale), $exponent, '0', STR_PAD_LEFT);
    }
}
