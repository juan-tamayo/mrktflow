<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use App\Domain\Catalog\Contracts\CatalogReader;
use App\Domain\Catalog\Exceptions\CatalogCategoryNotFound;
use App\Domain\Catalog\Exceptions\CatalogCategoryUnpublished;
use App\Domain\Catalog\Exceptions\CatalogProductNotFound;
use App\Domain\Catalog\Exceptions\CatalogProductUnpublished;
use App\Domain\CheckoutOrders\Support\CartMoney;
use App\Domain\ThemesContent\Data\SectionDefinition;
use App\Domain\ThemesContent\Exceptions\SectionValidationFailed;
use App\Domain\ThemesContent\Support\SectionRegistry;

/**
 * Validates section instances against the typed registry (THEME-02, T063a).
 *
 * A payload is either fully valid or rejected with every problem listed —
 * each error names the section, the field and what is wrong. Unknown block
 * types and unknown settings fields are hard rejects, never silently
 * dropped, and settings are checked against their declared types and
 * constraints (lengths, ranges, enums, item bounds, per-page repeat
 * limits). Bindings resolve only through the platform catalog read model:
 * a reference to a draft or nonexistent product/category fails with one
 * message that does not distinguish the two, and the validated output
 * carries the resolved public summaries — templates never see raw input
 * or unrestricted models, and invalid payloads never reach rendering.
 */
final class ValidateSections
{
    private const MAX_SECTIONS = 100;

    public function __construct(
        private readonly SectionRegistry $registry,
        private readonly CatalogReader $catalog,
    ) {}

    /**
     * @param  array<mixed>  $sections
     * @return list<array{id: string, type: string, version: int, settings: array<string, mixed>, binding: array<string, mixed>|null}>
     */
    public function validate(array $sections): array
    {
        $errors = [];
        if (! array_is_list($sections)) {
            throw new SectionValidationFailed(['Sections must be a list of section objects.']);
        }
        if (count($sections) > self::MAX_SECTIONS) {
            $errors[] = 'A page may contain at most '.self::MAX_SECTIONS.' sections; '.count($sections).' were supplied.';
        }
        $ids = [];
        $counts = [];
        $validated = [];
        foreach ($sections as $index => $section) {
            $result = $this->validateSection($section, $index, $ids, $counts, $errors);
            if ($result !== null) {
                $validated[] = $result;
            }
        }
        if ($errors !== []) {
            throw new SectionValidationFailed($errors);
        }

        return $validated;
    }

    /**
     * @param  array<string, true>  $ids
     * @param  array<string, int>  $counts
     * @param  list<string>  $errors
     * @return array{id: string, type: string, version: int, settings: array<string, mixed>, binding: array<string, mixed>|null}|null
     */
    private function validateSection(mixed $section, int $index, array &$ids, array &$counts, array &$errors): ?array
    {
        if (! is_array($section) || array_is_list($section)) {
            $errors[] = 'Section at position '.($index + 1).' must be an object with id, type, version and settings.';

            return null;
        }
        $id = $section['id'] ?? null;
        $label = is_string($id) && $id !== '' ? "Section '{$id}'" : 'Section at position '.($index + 1);
        $type = $section['type'] ?? null;
        if (! is_string($type) || $type === '') {
            $errors[] = "{$label} must declare a block 'type'.";

            return null;
        }
        $definition = $this->registry->definition($type);
        if ($definition === null) {
            $errors[] = "{$label} uses unknown block type '{$type}'; registered types are: "
                .implode(', ', array_keys($this->registry->definitions())).'.';

            return null;
        }
        $label = is_string($id) && $id !== '' ? "Section '{$id}' ({$type})" : 'Section at position '.($index + 1)." ({$type})";
        $errorCount = count($errors);
        $allowed = ['id', 'type', 'version', 'settings'];
        if ($definition->binding !== null) {
            $allowed[] = 'binding';
        }
        foreach (array_keys($section) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                $errors[] = "{$label} has an unknown field '{$key}'.";
            }
        }
        if (! is_string($id) || ! preg_match('/\A[a-z][a-z0-9-]{0,47}\z/', $id)) {
            $errors[] = "{$label} has an invalid 'id'; use a bounded lowercase identifier like 'intro'.";
        } elseif (isset($ids[$id])) {
            $errors[] = "Section id '{$id}' is used more than once; section ids must be distinct.";
        } else {
            $ids[$id] = true;
        }
        if (($section['version'] ?? null) !== $definition->version) {
            $errors[] = "{$label} declares 'version' ".json_encode($section['version'] ?? null)
                ." but the registered version of '{$type}' is {$definition->version}.";
        }
        $counts[$type] = ($counts[$type] ?? 0) + 1;
        if ($counts[$type] > $definition->maxPerPage) {
            $errors[] = "{$label} exceeds the per-page limit for '{$type}': at most {$definition->maxPerPage} per page.";
        }
        $settings = $section['settings'] ?? null;
        if (! is_array($settings) || (array_is_list($settings) && $settings !== [])) {
            $errors[] = "{$label} 'settings' must be an object of typed settings.";
            $settings = [];
        }
        $normalizedSettings = $this->validateFields($definition->settings, $settings, $label, 'setting', $errors);
        $binding = $this->validateBinding($definition, $section['binding'] ?? null, array_key_exists('binding', $section), $normalizedSettings ?? [], $label, $errors);
        if (count($errors) !== $errorCount || $normalizedSettings === null) {
            return null;
        }

        return [
            'id' => $id,
            'type' => $type,
            'version' => $definition->version,
            'settings' => $normalizedSettings,
            'binding' => $binding,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function validateFields(array $fields, array $values, string $label, string $path, array &$errors): ?array
    {
        $errorCount = count($errors);
        $known = [];
        foreach ($fields as $field) {
            $known[(string) $field['key']] = true;
        }
        foreach (array_keys($values) as $key) {
            if (! isset($known[(string) $key])) {
                $errors[] = "{$label} has an unknown {$path} '{$key}' for this block.";
            }
        }
        $normalized = [];
        foreach ($fields as $field) {
            $key = (string) $field['key'];
            if (! array_key_exists($key, $values)) {
                if (($field['required'] ?? false) === true) {
                    $errors[] = "{$label} is missing the required {$path} '{$key}'.";

                    continue;
                }
                if (array_key_exists('default', $field)) {
                    $normalized[$key] = $field['default'];
                }

                continue;
            }
            $value = $this->validateField($field, $values[$key], $label, "{$path} '{$key}'", $errors);
            if ($value !== null) {
                $normalized[$key] = $value;
            }
        }

        return count($errors) === $errorCount ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     */
    private function validateField(array $field, mixed $value, string $label, string $path, array &$errors): mixed
    {
        $type = (string) ($field['type'] ?? '');
        if ($type === 'text') {
            $max = (int) $field['maxLength'];
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max
                || preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value) === 1) {
                $errors[] = "{$label} {$path} must be plain text of at most {$max} characters, without markup or control characters.";

                return null;
            }

            return $value;
        }
        if ($type === 'integer') {
            if (! is_int($value)) {
                $errors[] = "{$label} {$path} must be an integer.";

                return null;
            }
            if ($value < (int) $field['min'] || $value > (int) $field['max']) {
                $errors[] = "{$label} {$path} must be between {$field['min']} and {$field['max']}.";

                return null;
            }

            return $value;
        }
        if ($type === 'boolean') {
            if (! is_bool($value)) {
                $errors[] = "{$label} {$path} must be true or false.";

                return null;
            }

            return $value;
        }
        if ($type === 'enum') {
            $options = array_map(static fn (mixed $option): string => (string) $option, $field['options']);
            if (! is_string($value) || ! in_array($value, $options, true)) {
                $errors[] = "{$label} {$path} must be one of: ".implode(', ', $options).'.';

                return null;
            }

            return $value;
        }
        if ($type === 'path') {
            if (! $this->isLocalPath($value)) {
                $errors[] = "{$label} {$path} must be a local path starting with '/' (no external URLs, traversal or parameters).";

                return null;
            }

            return $value;
        }
        if ($type === 'object') {
            if (! is_array($value) || (array_is_list($value) && $value !== [])) {
                $errors[] = "{$label} {$path} must be an object.";

                return null;
            }

            return $this->validateFields($field['fields'], $value, $label, "{$path} field", $errors);
        }
        if ($type === 'list') {
            if (! is_array($value) || ! array_is_list($value)) {
                $errors[] = "{$label} {$path} must be a list.";

                return null;
            }
            if (count($value) < (int) $field['minItems'] || count($value) > (int) $field['maxItems']) {
                $errors[] = "{$label} {$path} must contain between {$field['minItems']} and {$field['maxItems']} entries.";

                return null;
            }
            $items = [];
            foreach ($value as $position => $item) {
                if (! is_array($item) || (array_is_list($item) && $item !== [])) {
                    $errors[] = "{$label} {$path} entry ".($position + 1).' must be an object.';

                    continue;
                }
                $parsed = $this->validateFields($field['itemFields'], $item, $label, "{$path} entry ".($position + 1).' field', $errors);
                if ($parsed !== null) {
                    $items[] = $parsed;
                }
            }

            return $items;
        }
        $errors[] = "{$label} {$path} declares an unsupported field type.";

        return null;
    }

    private function isLocalPath(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 500
            && preg_match('#^/(?!/)[A-Za-z0-9/_.-]*$#D', $value) === 1
            && array_intersect(explode('/', $value), ['.', '..']) === [];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function validateBinding(SectionDefinition $definition, mixed $binding, bool $present, array $settings, string $label, array &$errors): ?array
    {
        $spec = $definition->binding;
        if ($spec === null) {
            return null;
        }
        if (! $present || $binding === null) {
            if ($spec['required']) {
                $errors[] = "{$label} requires a 'binding' naming the platform data it displays.";
            }

            return null;
        }
        if (! is_array($binding) || array_is_list($binding)) {
            $errors[] = "{$label} 'binding' must be an object with a kind and references.";

            return null;
        }
        $kind = $binding['kind'] ?? null;
        if (! is_string($kind) || ! in_array($kind, $spec['kinds'], true)) {
            $errors[] = "{$label} binding 'kind' must be one of: ".implode(', ', $spec['kinds']).'.';

            return null;
        }
        $single = in_array($kind, ['product', 'category'], true);
        $expected = $single ? ['kind', 'publicId'] : ['kind', 'publicIds'];
        foreach (array_keys($binding) as $key) {
            if (! in_array((string) $key, $expected, true)) {
                $errors[] = "{$label} binding has an unknown field '{$key}' for kind '{$kind}'.";
            }
        }
        if ($single) {
            $publicId = $binding['publicId'] ?? null;
            if (! is_string($publicId) || trim($publicId) === '') {
                $errors[] = "{$label} binding 'publicId' must name one {$kind}.";

                return null;
            }
            $resolved = $kind === 'product'
                ? $this->resolveProduct($publicId, $label, $errors)
                : $this->resolveCategory($publicId, $settings, true, $label, $errors);

            return $resolved === null ? null : ['kind' => $kind, 'publicId' => $publicId, 'resolved' => $resolved];
        }
        $publicIds = $binding['publicIds'] ?? null;
        $min = $spec['minItems'] ?? 1;
        if (! is_array($publicIds) || ! array_is_list($publicIds) || count($publicIds) < $min || count($publicIds) > $spec['maxItems']) {
            $errors[] = "{$label} binding 'publicIds' must list between {$min} and {$spec['maxItems']} references.";

            return null;
        }
        $seen = [];
        foreach ($publicIds as $reference) {
            if (! is_string($reference) || trim($reference) === '') {
                $errors[] = "{$label} binding 'publicIds' entries must be non-empty references.";

                return null;
            }
            if (isset($seen[strtolower($reference)])) {
                $errors[] = "{$label} binding 'publicIds' repeats the reference '{$reference}'.";

                return null;
            }
            $seen[strtolower($reference)] = true;
        }
        $resolved = [];
        foreach ($publicIds as $reference) {
            $entry = $kind === 'products'
                ? $this->resolveProduct($reference, $label, $errors)
                : $this->resolveCategory($reference, $settings, false, $label, $errors);
            if ($entry === null) {
                return null;
            }
            $resolved[] = $entry;
        }

        return ['kind' => $kind, 'publicIds' => $publicIds, 'resolved' => $resolved];
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function resolveProduct(string $publicId, string $label, array &$errors): ?array
    {
        try {
            $snapshot = $this->catalog->publicProduct($publicId);
        } catch (CatalogProductNotFound|CatalogProductUnpublished) {
            $errors[] = "{$label} binding references product '{$publicId}' which does not exist or is not published.";

            return null;
        }
        $variant = $snapshot['variants'][0];
        foreach ($snapshot['variants'] as $candidate) {
            if ($candidate['isDefault']) {
                $variant = $candidate;
                break;
            }
        }
        $money = CartMoney::format($variant['money']['amount'], $variant['money']['currency'], $variant['money']['exponent']);

        return [
            'id' => $snapshot['id'],
            'url' => '/products/'.$snapshot['slug'],
            'title' => $snapshot['title'],
            'description' => $snapshot['description'],
            'available' => $snapshot['available'],
            'availability' => $snapshot['availability'],
            'image' => $snapshot['image'],
            'money' => [
                'amountMinor' => $money['amount'],
                'currency' => $money['currency'],
                'exponent' => $money['exponent'],
                'formatted' => $money['formatted'],
            ],
            'attributes' => $snapshot['typed_attributes'],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function resolveCategory(string $publicId, array $settings, bool $withProducts, string $label, array &$errors): ?array
    {
        try {
            $category = $this->catalog->staffCategory($publicId);
        } catch (CatalogCategoryNotFound) {
            $category = null;
        }
        if ($category === null || $category['status'] !== 'published') {
            $errors[] = "{$label} binding references category '{$publicId}' which does not exist or is not published.";

            return null;
        }
        $summary = [
            'id' => $category['id'],
            'url' => '/categories/'.$category['path'],
            'title' => $category['title'],
        ];
        if (! $withProducts) {
            return $summary;
        }
        try {
            $snapshot = $this->catalog->publicCategory($category['path']);
        } catch (CatalogCategoryNotFound|CatalogCategoryUnpublished) {
            $errors[] = "{$label} binding references category '{$publicId}' which does not exist or is not published.";

            return null;
        }
        $limit = isset($settings['limit']) && is_int($settings['limit']) ? $settings['limit'] : null;
        $products = [];
        foreach ($snapshot['products'] as $product) {
            $money = CartMoney::format($product['money']['amount'], $product['money']['currency'], $product['money']['exponent']);
            $products[] = [
                'id' => $product['id'],
                'url' => '/products/'.$product['slug'],
                'title' => $product['title'],
                'available' => $product['available'],
                'availability' => $product['availability'],
                'image' => $product['image'],
                'money' => [
                    'amountMinor' => $money['amount'],
                    'currency' => $money['currency'],
                    'exponent' => $money['exponent'],
                    'formatted' => $money['formatted'],
                ],
            ];
            if ($limit !== null && count($products) >= $limit) {
                break;
            }
        }

        return $summary + ['description' => $snapshot['description'], 'products' => $products];
    }
}
