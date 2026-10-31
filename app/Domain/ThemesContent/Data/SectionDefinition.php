<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Data;

/**
 * One registered section block type (THEME-02, T063a): its stable type and
 * version, the typed settings fields a merchant may supply, the platform
 * data binding it may declare, and its per-page repeat limit.
 *
 * Field definitions are plain arrays in one of these shapes, by `type`:
 * - text:    {key, type, required, maxLength}
 * - integer: {key, type, required, min, max, default?}
 * - boolean: {key, type, required, default?}
 * - enum:    {key, type, required, options, default?}
 * - path:    {key, type, required} — a local root-relative path
 * - object:  {key, type, required, fields: list<field>}
 * - list:    {key, type, required, minItems, maxItems, itemFields: list<field>}
 *
 * The binding spec is {required, kinds, maxItems, minItems?} where kinds
 * are the platform binding kinds the block accepts: `product` and
 * `category` take one `publicId`; `products` and `categories` take a
 * `publicIds` list.
 */
final readonly class SectionDefinition
{
    /**
     * @param  list<array<string, mixed>>  $settings
     * @param  array{required: bool, kinds: list<string>, maxItems: int, minItems?: int}|null  $binding
     */
    public function __construct(
        public string $type,
        public int $version,
        public string $label,
        public array $settings,
        public ?array $binding,
        public int $maxPerPage,
    ) {}

    /** @return array<string, mixed>|null */
    public function field(string $key): ?array
    {
        foreach ($this->settings as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }
}
