<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Data;

use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use stdClass;

/** Data-only typed schema candidate. Core price/stock fields are outside schema control. */
final readonly class ProductSchemaDraft implements JsonSerializable
{
    /** @var list<string> */
    private const RESERVED = ['price', 'stock', 'sku', 'currency', 'quantity', 'on_hand', 'inventory', 'amount', 'price_amount', 'available_quantity'];

    /** @param array<string, mixed> $document */
    private function __construct(private array $document) {}

    public static function fromJson(string $json): self
    {
        if (strlen($json) > 32768) {
            throw new InvalidArgumentException('Product schema suggestion exceeds the byte limit.');
        }
        try {
            $data = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid product schema JSON.');
        }
        if (! $data instanceof stdClass) {
            throw new InvalidArgumentException('Product schema must be a JSON object.');
        }
        $data = get_object_vars($data);
        self::keys($data, ['schemaVersion', 'kind', 'key', 'label', 'comparison_group', 'default_template_key', 'attributes']);
        if ($data['schemaVersion'] !== '1.0' || $data['kind'] !== 'product_schema') {
            throw new InvalidArgumentException('Unsupported product schema contract.');
        }
        self::token($data['key'], 40, '/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/');
        self::text($data['label'], 80);
        self::token($data['comparison_group'], 40, '/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/');
        if ($data['default_template_key'] !== 'product.default' && $data['default_template_key'] !== 'product.compact') {
            throw new InvalidArgumentException('Unsupported product template key.');
        }
        if (! is_array($data['attributes']) || ! array_is_list($data['attributes']) || count($data['attributes']) < 1 || count($data['attributes']) > 30) {
            throw new InvalidArgumentException('Invalid schema attribute count.');
        }
        $keys = [];
        foreach ($data['attributes'] as $index => $attribute) {
            if (! $attribute instanceof stdClass) {
                throw new InvalidArgumentException('Schema attribute must be a JSON object.');
            }
            $attribute = get_object_vars($attribute);
            if (($attribute['options'] ?? null) instanceof stdClass) {
                throw new InvalidArgumentException('Schema options must be a list.');
            }
            self::keys($attribute, ['key', 'type', 'unit', 'required', 'comparable', 'filterable', 'label', 'options']);
            self::token($attribute['key'], 80, '/\A[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*){0,4}\z/');
            if (in_array($attribute['key'], self::RESERVED, true) || in_array(strtolower((string) $attribute['key']), self::RESERVED, true)) {
                throw new InvalidArgumentException('Price and stock stay core catalog fields.');
            }
            if (in_array($attribute['key'], $keys, true)) {
                throw new InvalidArgumentException('Duplicate schema attribute key.');
            }
            $keys[] = $attribute['key'];
            if (! in_array($attribute['type'], ['string', 'integer', 'decimal', 'boolean', 'date', 'enum'], true)) {
                throw new InvalidArgumentException('Unsupported schema attribute type.');
            }
            if (! is_bool($attribute['required']) || ! is_bool($attribute['comparable']) || ! is_bool($attribute['filterable'])) {
                throw new InvalidArgumentException('Schema flags must be boolean.');
            }
            self::text($attribute['label'], 120);
            $unit = $attribute['unit'];
            if ($unit === '' || $unit === null) {
                $attribute['unit'] = null;
            } elseif (! is_string($unit) || preg_match('/\A[A-Za-z%][A-Za-z0-9%]{0,15}\z/', $unit) !== 1) {
                throw new InvalidArgumentException('Invalid schema unit.');
            } elseif (! in_array($attribute['type'], ['integer', 'decimal'], true)) {
                throw new InvalidArgumentException('Units apply only to numeric attributes.');
            }
            $options = $attribute['options'];
            if (! is_array($options) || ! array_is_list($options)) {
                throw new InvalidArgumentException('Schema options must be a list.');
            }
            if ($attribute['type'] === 'enum') {
                if (count($options) < 2 || count($options) > 30) {
                    throw new InvalidArgumentException('Enum attributes need two to thirty choices.');
                }
                $seen = [];
                foreach ($options as $option) {
                    if (! is_string($option) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $option) !== 1 || in_array($option, $seen, true)) {
                        throw new InvalidArgumentException('Invalid enum choice.');
                    }
                    $seen[] = $option;
                }
            } else {
                if ($options !== []) {
                    throw new InvalidArgumentException('Only enum attributes include choices.');
                }
                $attribute['options'] = [];
            }
            $data['attributes'][$index] = $attribute;
        }

        return new self($data);
    }

    /** @param list<string> $expected */
    private static function keys(mixed $object, array $expected): void
    {
        if (! is_array($object) || count($object) !== count($expected) || array_diff($expected, array_keys($object)) !== []) {
            throw new InvalidArgumentException('Unexpected product schema fields.');
        }
    }

    private static function text(mixed $text, int $maximum): void
    {
        if (! is_string($text) || trim($text) === '' || mb_strlen($text) > $maximum || preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $text)) {
            throw new InvalidArgumentException('Product schema requires bounded plain text.');
        }
    }

    private static function token(mixed $value, int $maximum, string $pattern): void
    {
        if (! is_string($value) || $value === '' || strlen($value) > $maximum || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException('Invalid product schema identity.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->document;
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     comparison_group: string,
     *     default_template_key: string,
     *     attributes: list<array<string, mixed>>
     * }
     */
    public function catalogInput(): array
    {
        $attributes = $this->document['attributes'] ?? [];
        if (! is_array($attributes) || ! array_is_list($attributes)) {
            throw new InvalidArgumentException('Invalid stored schema attributes.');
        }

        return [
            'key' => (string) $this->document['key'],
            'label' => (string) $this->document['label'],
            'comparison_group' => (string) $this->document['comparison_group'],
            'default_template_key' => (string) $this->document['default_template_key'],
            'attributes' => $attributes,
        ];
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
        $text = static fn (int $maximum): array => ['type' => 'string', 'minLength' => 1, 'maxLength' => $maximum, 'pattern' => '^[^<>\\u0000-\\u0008\\u000b\\u000c\\u000e-\\u001f\\u007f]+$'];

        return $object([
            'schemaVersion' => ['type' => 'string', 'enum' => ['1.0']],
            'kind' => ['type' => 'string', 'enum' => ['product_schema']],
            'key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40, 'pattern' => '^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$'],
            'label' => $text(80),
            'comparison_group' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40, 'pattern' => '^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$'],
            'default_template_key' => ['type' => 'string', 'enum' => ['product.default', 'product.compact']],
            'attributes' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 30,
                'items' => $object([
                    'key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80, 'pattern' => '^[a-z][a-z0-9]*(?:\\.[a-z][a-z0-9]*){0,4}$'],
                    'type' => ['type' => 'string', 'enum' => ['string', 'integer', 'decimal', 'boolean', 'date', 'enum']],
                    'unit' => ['type' => 'string', 'maxLength' => 16],
                    'required' => ['type' => 'boolean'],
                    'comparable' => ['type' => 'boolean'],
                    'filterable' => ['type' => 'boolean'],
                    'label' => $text(120),
                    'options' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string', 'maxLength' => 40]],
                ]),
            ],
        ]);
    }
}
