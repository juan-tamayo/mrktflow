<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use App\Domain\ThemesContent\Support\VisualTemplates;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the visual template for a product, category or page (THEME-02, T066).
 *
 * An explicit layout assignment wins. Products without one fall back to the
 * assigned schema's default template key, then to the theme default. Prices
 * and attributes are never read from the assignment.
 */
final class ResolveLayout
{
    public function templateKey(string $subjectType, string $subjectId): string
    {
        $fallback = VisualTemplates::fallback($subjectType);
        $assigned = DB::table('layout_assignments')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->value('template_key');
        if (is_string($assigned) && VisualTemplates::allows($subjectType, $assigned)) {
            return $assigned;
        }
        if ($subjectType === 'product') {
            $schemaDefault = DB::table('products')
                ->leftJoin('product_schema_versions', 'products.schema_version_id', '=', 'product_schema_versions.id')
                ->where('products.id', $subjectId)
                ->value('product_schema_versions.default_template_key');
            if (is_string($schemaDefault) && VisualTemplates::allows('product', $schemaDefault)) {
                return $schemaDefault;
            }
        }

        return $fallback;
    }

    public function view(string $subjectType, string $subjectId): string
    {
        return VisualTemplates::view($subjectType, $this->templateKey($subjectType, $subjectId));
    }
}
