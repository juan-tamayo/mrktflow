<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

final class VariantOptions
{
    /**
     * @param  array<string, string>  $options
     */
    public static function fingerprint(array $options): string
    {
        $normalized = [];
        foreach ($options as $name => $value) {
            $normalized[mb_strtolower(trim($name), 'UTF-8')] = mb_strtolower(trim($value), 'UTF-8');
        }
        ksort($normalized);
        if ($normalized === []) {
            return '';
        }

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  list<array{name: string, value: string}>  $options
     */
    public static function label(array $options): string
    {
        if ($options === []) {
            return 'Standard';
        }
        $values = [];
        foreach ($options as $option) {
            $values[] = $option['value'];
        }

        return implode(' / ', $values);
    }
}
