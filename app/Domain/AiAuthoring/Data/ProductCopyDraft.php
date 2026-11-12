<?php

declare(strict_types=1);

namespace App\Domain\AiAuthoring\Data;

use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use stdClass;

/** Data-only product copy candidate. Price, stock and publication stay merchant-authored. */
final readonly class ProductCopyDraft implements JsonSerializable
{
    /** @param array<string, mixed> $document */
    private function __construct(private array $document) {}

    public static function fromJson(string $json, string $productId): self
    {
        if (strlen($json) > 16384) {
            throw new InvalidArgumentException('Product copy exceeds the byte limit.');
        }
        try {
            $data = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid product copy JSON.');
        }
        if (! $data instanceof stdClass) {
            throw new InvalidArgumentException('Product copy must be a JSON object.');
        }
        $data = get_object_vars($data);
        self::keys($data, ['schemaVersion', 'kind', 'productId', 'title', 'description']);
        if ($data['schemaVersion'] !== '1.0' || $data['kind'] !== 'product_copy' || $data['productId'] !== $productId) {
            throw new InvalidArgumentException('Unsupported product copy contract.');
        }
        self::text($data['title'], 200);
        self::text($data['description'], 5000);

        return new self($data);
    }

    /** @param list<string> $expected */
    private static function keys(mixed $object, array $expected): void
    {
        if (! is_array($object) || count($object) !== count($expected) || array_diff($expected, array_keys($object)) !== []) {
            throw new InvalidArgumentException('Unexpected product copy fields.');
        }
    }

    private static function text(mixed $text, int $maximum): void
    {
        if (! is_string($text) || trim($text) === '' || mb_strlen($text) > $maximum || preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $text)) {
            throw new InvalidArgumentException('Product copy requires bounded plain text.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->document;
    }

    /** @return array<string, mixed> */
    public static function schema(string $productId): array
    {
        $object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
        $text = static fn (int $maximum): array => ['type' => 'string', 'minLength' => 1, 'maxLength' => $maximum, 'pattern' => '^[^<>\\u0000-\\u0008\\u000b\\u000c\\u000e-\\u001f\\u007f]+$'];

        return $object([
            'schemaVersion' => ['type' => 'string', 'enum' => ['1.0']],
            'kind' => ['type' => 'string', 'enum' => ['product_copy']],
            'productId' => ['type' => 'string', 'enum' => [$productId]],
            'title' => $text(200),
            'description' => $text(5000),
        ]);
    }
}
