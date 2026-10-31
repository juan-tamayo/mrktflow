<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Reads one section draft document (THEME-02, T064a). A target with no
 * saved draft reads as version 0 with no sections — the editor's starting
 * state, not an error.
 */
final class ReadSectionDraft
{
    /** @return array{version: int, sections: list<array<string, mixed>>, updatedAt: string|null} */
    public function read(string $page, string $slot): array
    {
        $row = DB::table('content_drafts')->where('page_key', $page)->where('slot', $slot)->first();
        if ($row === null) {
            return ['version' => 0, 'sections' => [], 'updatedAt' => null];
        }
        $decoded = json_decode((string) $row->sections, true, flags: JSON_THROW_ON_ERROR);
        $sections = [];
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (is_array($item)) {
                    $sections[] = $item;
                }
            }
        }

        return [
            'version' => (int) $row->version,
            'sections' => $sections,
            'updatedAt' => $row->updated_at === null ? null : (string) $row->updated_at,
        ];
    }
}
