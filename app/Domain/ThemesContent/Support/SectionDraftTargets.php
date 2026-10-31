<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Support;

use App\Domain\Storefront\Support\SystemSlots;

/**
 * The (page, slot) targets a section draft may live in (THEME-02, T064a).
 *
 * Derived from the T062 slot contract, never restated by hand: a target is
 * a known storefront page and one of its theme slots in the announcement
 * or main region — the slots that can hold rendered section blocks.
 * Platform slots (the page chrome and the commerce actions) and the head
 * slot are not section documents, and requests or imports cannot invent
 * new targets.
 */
final class SectionDraftTargets
{
    /** @return list<string> */
    public static function pages(): array
    {
        return SystemSlots::pages();
    }

    /** @return list<string> */
    public static function slots(string $page): array
    {
        if (! in_array($page, self::pages(), true)) {
            return [];
        }
        $slots = [];
        foreach (SystemSlots::forPage($page) as $slot) {
            if ($slot['kind'] === 'theme' && in_array($slot['region'], ['announcement', 'main'], true)) {
                $slots[] = $slot['name'];
            }
        }

        return $slots;
    }

    public static function isEditable(string $page, string $slot): bool
    {
        return in_array($slot, self::slots($page), true);
    }

    /** @return list<array{page: string, slots: list<string>}> */
    public static function all(): array
    {
        $targets = [];
        foreach (self::pages() as $page) {
            $targets[] = ['page' => $page, 'slots' => self::slots($page)];
        }

        return $targets;
    }
}
