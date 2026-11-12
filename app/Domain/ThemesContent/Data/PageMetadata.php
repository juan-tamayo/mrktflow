<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Data;

final readonly class PageMetadata
{
    /**
     * @param  list<array<string, mixed>>  $graph
     */
    public function __construct(
        public string $canonical,
        public string $title,
        public string $description,
        public string $robots,
        public string $ogType,
        public ?string $ogImage,
        public array $graph,
    ) {}

    public function jsonLd(): string
    {
        return json_encode([
            '@context' => 'https://schema.org',
            '@graph' => $this->graph,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
