<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Support;

use App\Domain\ThemesContent\Exceptions\SectionValidationFailed;

/**
 * Groups validator failures for the section editor (THEME-02, T064a).
 *
 * T063's validator already names the section in every message it raises
 * for one section ("Section 'intro' (hero) setting 'heading' …", or
 * "Section at position 3 …" when the section's own id is the problem).
 * This groups those messages under the section they belong to — keyed by
 * section id, or `position-N` (1-based) when the id itself is invalid —
 * so the editor can show each problem on the block that caused it and let
 * the editor fix and re-save without losing work. Everything else
 * (duplicate ids, the document size cap, a payload that is not a list)
 * is a document-level error.
 */
final class SectionValidationErrors
{
    /** @return array{document: list<string>, sections: array<string, list<string>>} */
    public static function group(SectionValidationFailed $failure): array
    {
        $document = [];
        $sections = [];
        foreach ($failure->errors() as $error) {
            if (preg_match("/\ASection '([^']+)'/", $error, $matches) === 1) {
                $sections[$matches[1]][] = $error;
            } elseif (preg_match('/\ASection at position (\d+)/', $error, $matches) === 1) {
                $sections['position-'.$matches[1]][] = $error;
            } else {
                $document[] = $error;
            }
        }

        return ['document' => $document, 'sections' => $sections];
    }
}
