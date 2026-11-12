<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Support;

use InvalidArgumentException;

/**
 * Assignable visual templates (THEME-02, T066). Product schemas keep their
 * own default key as a fallback; an assignment never copies prices or
 * attribute values.
 */
final class VisualTemplates
{
    /** @var array<string, array<string, array{view: string, label: string}>> */
    private const TEMPLATES = [
        'product' => [
            'product.default' => ['view' => 'default-theme::product', 'label' => 'Default product layout'],
            'product.compact' => ['view' => 'default-theme::product-compact', 'label' => 'Compact product layout'],
        ],
        'category' => [
            'category.default' => ['view' => 'default-theme::category', 'label' => 'Default category layout'],
            'category.landing' => ['view' => 'default-theme::category-landing', 'label' => 'Category landing layout'],
        ],
        'page' => [
            'page.default' => ['view' => 'default-theme::page', 'label' => 'Default page layout'],
            'page.narrow' => ['view' => 'default-theme::page-narrow', 'label' => 'Narrow page layout'],
        ],
    ];

    /** @var array<string, string> */
    private const PAGE_SUBJECTS = [
        'product' => 'product',
        'category' => 'category',
        'page' => 'page',
    ];

    /** @return list<string> */
    public static function subjects(): array
    {
        return array_keys(self::TEMPLATES);
    }

    public static function fallback(string $subjectType): string
    {
        $keys = self::keys($subjectType);

        return $keys[0];
    }

    /** @return list<string> */
    public static function keys(string $subjectType): array
    {
        return array_keys(self::definitions($subjectType));
    }

    public static function allows(string $subjectType, string $templateKey): bool
    {
        return isset(self::TEMPLATES[$subjectType][$templateKey]);
    }

    /** @return view-string */
    public static function view(string $subjectType, string $templateKey): string
    {
        return match ([$subjectType, $templateKey]) {
            ['product', 'product.default'] => 'default-theme::product',
            ['product', 'product.compact'] => 'default-theme::product-compact',
            ['category', 'category.default'] => 'default-theme::category',
            ['category', 'category.landing'] => 'default-theme::category-landing',
            ['page', 'page.default'] => 'default-theme::page',
            ['page', 'page.narrow'] => 'default-theme::page-narrow',
            default => throw new InvalidArgumentException("Template [{$templateKey}] is not assignable to [{$subjectType}]."),
        };
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function options(string $subjectType): array
    {
        $options = [];
        foreach (self::definitions($subjectType) as $key => $definition) {
            $options[] = ['key' => $key, 'label' => $definition['label']];
        }

        return $options;
    }

    public static function subjectForPage(string $page): string
    {
        if (! isset(self::PAGE_SUBJECTS[$page])) {
            throw new InvalidArgumentException("Page [{$page}] does not accept visual template assignment.");
        }

        return self::PAGE_SUBJECTS[$page];
    }

    /**
     * @return array<string, array{view: string, label: string}>
     */
    private static function definitions(string $subjectType): array
    {
        if (! isset(self::TEMPLATES[$subjectType])) {
            throw new InvalidArgumentException("Unknown layout subject [{$subjectType}].");
        }

        return self::TEMPLATES[$subjectType];
    }
}
