<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Support;

use App\Domain\ThemesContent\Data\SectionDefinition;
use InvalidArgumentException;

/**
 * The typed section registry (THEME-02, T063a).
 *
 * The built-in block definitions ship as data with the default theme
 * (`themes/default/sections/definitions.json`). The registry parses that
 * file strictly — an unknown definition or field key, a duplicate type, or
 * a malformed constraint fails loudly at load, because a registry the
 * validator cannot trust would let unvalidated data reach templates.
 * Developer themes register additional blocks by shipping definitions in
 * the same format through the same loader.
 */
final class SectionRegistry
{
    /** @var array<string, SectionDefinition>|null */
    private ?array $definitions = null;

    private readonly string $definitionsPath;

    public function __construct(?string $definitionsPath = null)
    {
        $this->definitionsPath = $definitionsPath ?? base_path('themes/default/sections/definitions.json');
    }

    /** @return array<string, SectionDefinition> */
    public function definitions(): array
    {
        if ($this->definitions === null) {
            $this->definitions = $this->load();
        }

        return $this->definitions;
    }

    public function definition(string $type): ?SectionDefinition
    {
        return $this->definitions()[$type] ?? null;
    }

    /** @return array<string, SectionDefinition> */
    private function load(): array
    {
        $raw = file_get_contents($this->definitionsPath);
        if ($raw === false) {
            throw new InvalidArgumentException("Section definitions cannot be read from '{$this->definitionsPath}'.");
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Section definitions must be a JSON object.');
        }
        $this->exactKeys($data, ['schemaVersion', 'sections'], 'Section definitions');
        if ($data['schemaVersion'] !== '1.0') {
            throw new InvalidArgumentException("Section definitions schemaVersion must be '1.0'.");
        }
        if (! is_array($data['sections']) || ! array_is_list($data['sections']) || $data['sections'] === []) {
            throw new InvalidArgumentException('Section definitions must declare a non-empty sections list.');
        }
        $definitions = [];
        foreach ($data['sections'] as $entry) {
            $definition = $this->parseDefinition($entry);
            if (isset($definitions[$definition->type])) {
                throw new InvalidArgumentException("Section definitions repeat the block type '{$definition->type}'.");
            }
            $definitions[$definition->type] = $definition;
        }

        return $definitions;
    }

    private function parseDefinition(mixed $entry): SectionDefinition
    {
        if (! is_array($entry) || array_is_list($entry)) {
            throw new InvalidArgumentException('Each section definition must be an object.');
        }
        $this->exactKeys($entry, ['type', 'version', 'label', 'settings', 'binding', 'limits'], 'Section definition');
        $type = $entry['type'];
        if (! is_string($type) || ! preg_match('/^[a-z][a-z0-9-]*$/D', $type)) {
            throw new InvalidArgumentException('Section definition type must be a block identifier like product-grid.');
        }
        if ($entry['version'] !== 1) {
            throw new InvalidArgumentException("Section definition '{$type}' must declare integer version 1.");
        }
        if (! is_string($entry['label']) || trim($entry['label']) === '' || strlen($entry['label']) > 120) {
            throw new InvalidArgumentException("Section definition '{$type}' must declare a bounded label.");
        }
        if (! is_array($entry['settings']) || ! array_is_list($entry['settings'])) {
            throw new InvalidArgumentException("Section definition '{$type}' settings must be a list of field definitions.");
        }
        $fields = [];
        $seen = [];
        foreach ($entry['settings'] as $field) {
            $parsed = $this->parseField($field, $type);
            if (isset($seen[$parsed['key']])) {
                throw new InvalidArgumentException("Section definition '{$type}' repeats the settings field '{$parsed['key']}'.");
            }
            $seen[$parsed['key']] = true;
            $fields[] = $parsed;
        }
        $limits = $entry['limits'];
        if (! is_array($limits) || array_is_list($limits)) {
            throw new InvalidArgumentException("Section definition '{$type}' limits must be an object.");
        }
        $this->exactKeys($limits, ['maxPerPage'], "Section definition '{$type}' limits");
        if (! is_int($limits['maxPerPage']) || $limits['maxPerPage'] < 1 || $limits['maxPerPage'] > 100) {
            throw new InvalidArgumentException("Section definition '{$type}' limits.maxPerPage must be an integer between 1 and 100.");
        }

        return new SectionDefinition(
            type: $type,
            version: 1,
            label: $entry['label'],
            settings: $fields,
            binding: $this->parseBinding($entry['binding'], $type),
            maxPerPage: $limits['maxPerPage'],
        );
    }

    /**
     * @return array{required: bool, kinds: list<string>, maxItems: int, minItems?: int}|null
     */
    private function parseBinding(mixed $binding, string $type): ?array
    {
        if ($binding === null) {
            return null;
        }
        if (! is_array($binding) || array_is_list($binding)) {
            throw new InvalidArgumentException("Section definition '{$type}' binding must be an object or null.");
        }
        foreach (array_keys($binding) as $key) {
            if (! in_array((string) $key, ['required', 'kinds', 'maxItems', 'minItems'], true)) {
                throw new InvalidArgumentException("Section definition '{$type}' binding has an unknown key '{$key}'.");
            }
        }
        if (! is_bool($binding['required'] ?? null) || ! is_array($binding['kinds'] ?? null) || ! array_is_list($binding['kinds']) || $binding['kinds'] === []) {
            throw new InvalidArgumentException("Section definition '{$type}' binding must declare required and a non-empty kinds list.");
        }
        foreach ($binding['kinds'] as $kind) {
            if (! in_array($kind, ['product', 'products', 'category', 'categories'], true)) {
                throw new InvalidArgumentException("Section definition '{$type}' binding kind must be product, products, category or categories.");
            }
        }
        if (! is_int($binding['maxItems'] ?? null) || $binding['maxItems'] < 1 || $binding['maxItems'] > 100) {
            throw new InvalidArgumentException("Section definition '{$type}' binding maxItems must be an integer between 1 and 100.");
        }
        $parsed = ['required' => $binding['required'], 'kinds' => $binding['kinds'], 'maxItems' => $binding['maxItems']];
        if (array_key_exists('minItems', $binding)) {
            if (! is_int($binding['minItems']) || $binding['minItems'] < 1 || $binding['minItems'] > $binding['maxItems']) {
                throw new InvalidArgumentException("Section definition '{$type}' binding minItems must be an integer between 1 and maxItems.");
            }
            $parsed['minItems'] = $binding['minItems'];
        }

        return $parsed;
    }

    /** @return array<string, mixed> */
    private function parseField(mixed $field, string $type): array
    {
        if (! is_array($field) || array_is_list($field)) {
            throw new InvalidArgumentException("Section definition '{$type}' settings entries must be field objects.");
        }
        $key = $field['key'] ?? null;
        if (! is_string($key) || ! preg_match('/^[a-z][a-zA-Z0-9]*$/D', $key)) {
            throw new InvalidArgumentException("Section definition '{$type}' has a settings field with an invalid key.");
        }
        if (! is_bool($field['required'] ?? null)) {
            throw new InvalidArgumentException("Section definition '{$type}' field '{$key}' must declare required as a boolean.");
        }
        $fieldType = $field['type'] ?? null;
        $allowed = match ($fieldType) {
            'text' => ['key', 'type', 'required', 'maxLength'],
            'integer' => ['key', 'type', 'required', 'min', 'max', 'default'],
            'boolean' => ['key', 'type', 'required', 'default'],
            'enum' => ['key', 'type', 'required', 'options', 'default'],
            'path' => ['key', 'type', 'required'],
            'object' => ['key', 'type', 'required', 'fields'],
            'list' => ['key', 'type', 'required', 'minItems', 'maxItems', 'itemFields'],
            default => throw new InvalidArgumentException("Section definition '{$type}' field '{$key}' has an unknown type."),
        };
        foreach (array_keys($field) as $name) {
            if (! in_array((string) $name, $allowed, true)) {
                throw new InvalidArgumentException("Section definition '{$type}' field '{$key}' has an unknown key '{$name}'.");
            }
        }
        if ($fieldType === 'text') {
            if (! is_int($field['maxLength'] ?? null) || $field['maxLength'] < 1 || $field['maxLength'] > 20000) {
                throw new InvalidArgumentException("Section definition '{$type}' field '{$key}' maxLength must be an integer between 1 and 20000.");
            }
        } elseif ($fieldType === 'integer') {
            if (! is_int($field['min'] ?? null) || ! is_int($field['max'] ?? null) || $field['min'] > $field['max']) {
                throw new InvalidArgumentException("Section definition '{$type}' field '{$key}' must declare an integer min not above its max.");
            }
            if (array_key_exists('default', $field) && (! is_int($field['default']) || $field['default'] < $field['min'] || $field['default'] > $field['max'])) {
                throw new InvalidArgumentException("Section definition '{$type}' field '{$key}' default must be an integer inside its range.");
            }
        } elseif ($fieldType === 'boolean') {
            if (array_key_exists('default', $field) && ! is_bool($field['default'])) {
                throw new InvalidArgumentException("Section definition '{$type}' field '{$key}' default must be a boolean.");
            }
        } elseif ($fieldType === 'enum') {
            $options = $field['options'] ?? null;
            if (! is_array($options) || ! array_is_list($options) || $options === []) {
                throw new InvalidArgumentException("Section definition '{$type}' field '{$key}' must declare a non-empty options list.");
            }
            foreach ($options as $option) {
                if (! is_string($option) || $option === '') {
                    throw new InvalidArgumentException("Section definition '{$type}' field '{$key}' options must be non-empty strings.");
                }
            }
            if (array_key_exists('default', $field) && ! in_array($field['default'], $options, true)) {
                throw new InvalidArgumentException("Section definition '{$type}' field '{$key}' default must be one of its options.");
            }
        } elseif ($fieldType === 'object' || $fieldType === 'list') {
            $subKey = $fieldType === 'object' ? 'fields' : 'itemFields';
            if ($fieldType === 'list' && (! is_int($field['minItems'] ?? null) || ! is_int($field['maxItems'] ?? null)
                || $field['minItems'] < 0 || $field['maxItems'] < 1 || $field['minItems'] > $field['maxItems'])) {
                throw new InvalidArgumentException("Section definition '{$type}' field '{$key}' must declare list item bounds with minItems not above maxItems.");
            }
            $subFields = $field[$subKey] ?? null;
            if (! is_array($subFields) || ! array_is_list($subFields) || $subFields === []) {
                throw new InvalidArgumentException("Section definition '{$type}' field '{$key}' must declare a non-empty {$subKey} list.");
            }
            $parsed = [];
            $seen = [];
            foreach ($subFields as $subField) {
                $parsedSub = $this->parseField($subField, $type);
                if (isset($seen[$parsedSub['key']])) {
                    throw new InvalidArgumentException("Section definition '{$type}' field '{$key}' repeats the sub-field '{$parsedSub['key']}'.");
                }
                $seen[$parsedSub['key']] = true;
                $parsed[] = $parsedSub;
            }
            $field[$subKey] = $parsed;
        }

        return $field;
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  list<string>  $expected
     */
    private function exactKeys(array $object, array $expected, string $subject): void
    {
        foreach (array_keys($object) as $key) {
            if (! in_array((string) $key, $expected, true)) {
                throw new InvalidArgumentException("{$subject} has an unknown key '{$key}'.");
            }
        }
        foreach ($expected as $key) {
            if (! array_key_exists($key, $object)) {
                throw new InvalidArgumentException("{$subject} is missing the key '{$key}'.");
            }
        }
    }
}
