<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Data;

use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use stdClass;

/** Data-only page candidate. No rendering, reference lookup, save or publishing authority. */
final readonly class PageDraft implements JsonSerializable
{
    /** @param array<string, mixed> $document */
    private function __construct(private array $document) {}

    /** @param list<string> $allowedProductIds */
    public static function fromJson(string $json, array $allowedProductIds): self
    {
        if (strlen($json) > 65536) {
            throw new InvalidArgumentException('Page draft exceeds the byte limit.');
        }
        try {
            $data = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid page draft JSON.');
        }
        if (! $data instanceof stdClass) {
            throw new InvalidArgumentException('Page draft must be a JSON object.');
        }
        $data = get_object_vars($data);
        self::keys($data, ['schemaVersion', 'kind', 'title', 'templateKey', 'sections']);
        if ($data['schemaVersion'] !== '1.0' || $data['kind'] !== 'page' || $data['templateKey'] !== 'page.default') {
            throw new InvalidArgumentException('Unsupported page draft contract.');
        }
        self::text($data['title'], 200);
        if (! is_array($data['sections']) || ! array_is_list($data['sections']) || count($data['sections']) < 1 || count($data['sections']) > 30) {
            throw new InvalidArgumentException('Invalid section count.');
        }
        $ids = [];
        foreach ($data['sections'] as $index => $section) {
            if (! $section instanceof stdClass) {
                throw new InvalidArgumentException('Section must be a JSON object.');
            }
            $section = get_object_vars($section);
            if (($section['settings'] ?? null) instanceof stdClass) {
                $section['settings'] = get_object_vars($section['settings']);
            }
            if (($section['binding'] ?? null) instanceof stdClass) {
                $section['binding'] = get_object_vars($section['binding']);
            }
            $data['sections'][$index] = $section;
            $type = $section['type'] ?? null;
            self::keys($section, $type === 'product-grid' ? ['id', 'type', 'version', 'settings', 'binding'] : ['id', 'type', 'version', 'settings']);
            if (! is_string($section['id']) || ! preg_match('/\A[a-z][a-z0-9-]{0,47}\z/', $section['id']) || in_array($section['id'], $ids, true) || $section['version'] !== 1) {
                throw new InvalidArgumentException('Invalid or duplicate section identity.');
            }
            $ids[] = $section['id'];
            if ($type === 'hero') {
                self::keys($section['settings'], ['heading', 'alignment']);
                self::text($section['settings']['heading'], 200);
                if (! in_array($section['settings']['alignment'], ['left', 'center'], true)) {
                    throw new InvalidArgumentException('Invalid heading alignment.');
                }
            } elseif ($type === 'rich-text') {
                self::keys($section['settings'], ['text']);
                self::text($section['settings']['text'], 5000);
            } elseif ($type === 'product-grid') {
                self::keys($section['settings'], ['heading', 'limit']);
                self::text($section['settings']['heading'], 200);
                $limit = $section['settings']['limit'];
                self::keys($section['binding'], ['kind', 'publicIds']);
                $references = $section['binding']['publicIds'];
                if (! is_int($limit) || $limit < 1 || $limit > 24 || $section['binding']['kind'] !== 'products'
                    || ! is_array($references) || ! array_is_list($references) || count($references) < 1 || count($references) > 20) {
                    throw new InvalidArgumentException('Invalid product binding.');
                }
                $seen = [];
                foreach ($references as $reference) {
                    if (! is_string($reference) || ! in_array($reference, $allowedProductIds, true) || in_array(strtolower($reference), $seen, true)) {
                        throw new InvalidArgumentException('Unknown or duplicate public product reference.');
                    }
                    $seen[] = strtolower($reference);
                }
            } else {
                throw new InvalidArgumentException('Unknown page section.');
            }
        }

        return new self($data);
    }

    /** @param list<string> $expected */
    private static function keys(mixed $object, array $expected): void
    {
        if (! is_array($object) || count($object) !== count($expected) || array_diff($expected, array_keys($object)) !== []) {
            throw new InvalidArgumentException('Unexpected page draft fields.');
        }
    }

    private static function text(mixed $text, int $maximum): void
    {
        if (! is_string($text) || trim($text) === '' || mb_strlen($text) > $maximum || preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $text)) {
            throw new InvalidArgumentException('Page draft requires bounded plain text.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->document;
    }

    /** @param list<string> $allowedProductIds
     * @return array<string, mixed>
     */
    public static function schema(array $allowedProductIds): array
    {
        $object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
        $text = static fn (int $maximum): array => ['type' => 'string', 'minLength' => 1, 'maxLength' => $maximum, 'pattern' => '^[^<>\\u0000-\\u0008\\u000b\\u000c\\u000e-\\u001f\\u007f]+$'];
        $section = static fn (string $type, array $settings): array => $object([
            'id' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9-]{0,47}$'], 'type' => ['type' => 'string', 'enum' => [$type]],
            'version' => ['type' => 'integer', 'enum' => [1]], 'settings' => $object($settings),
        ]);
        $sections = [
            $section('hero', ['heading' => $text(200), 'alignment' => ['type' => 'string', 'enum' => ['left', 'center']]]),
            $section('rich-text', ['text' => $text(5000)]),
        ];
        if ($allowedProductIds !== []) {
            $grid = $section('product-grid', ['heading' => $text(200), 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 24]]);
            $grid['properties']['binding'] = $object(['kind' => ['type' => 'string', 'enum' => ['products']], 'publicIds' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => ['type' => 'string', 'enum' => $allowedProductIds]]]);
            $grid['required'][] = 'binding';
            $sections[] = $grid;
        }

        return $object([
            'schemaVersion' => ['type' => 'string', 'enum' => ['1.0']], 'kind' => ['type' => 'string', 'enum' => ['page']], 'title' => $text(200),
            'templateKey' => ['type' => 'string', 'enum' => ['page.default']],
            'sections' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 30, 'items' => ['anyOf' => $sections]],
        ]);
    }
}
