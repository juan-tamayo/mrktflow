<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Actions;

use App\Domain\Storefront\Data\ComposedPage;
use App\Domain\Storefront\Exceptions\SlotCompositionRefused;
use App\Domain\Storefront\Support\SystemSlots;

/**
 * Resolves a storefront page against the SystemSlots contract.
 *
 * The layout's view composer runs this for every themed render with any
 * slot assignments supplied as view data, so data-driven composition
 * (imported documents, section systems, AI output) reaches the page only
 * through here. Assignments naming an unknown slot, or content for a
 * platform slot — a commerce action or the page chrome — are refused;
 * assignments for theme slots pass through as the slot's view.
 */
final class ComposePage
{
    /**
     * @param  array<string, string>  $themeSlotViews  Slot name => theme view name.
     */
    public function compose(string $page, array $themeSlotViews = []): ComposedPage
    {
        $slots = SystemSlots::forPage($page);
        foreach ($themeSlotViews as $name => $view) {
            $index = null;
            foreach ($slots as $candidate => $slot) {
                if ($slot['name'] === $name) {
                    $index = $candidate;
                    break;
                }
            }
            if ($index === null) {
                throw new SlotCompositionRefused("Page [{$page}] has no slot [{$name}] to assign.");
            }
            if ($slots[$index]['kind'] !== 'theme') {
                throw new SlotCompositionRefused("Slot [{$name}] on page [{$page}] is platform-rendered and cannot be supplied by a theme.");
            }
            $slots[$index]['view'] = $view;
        }

        return new ComposedPage($page, SystemSlots::template($page), $slots);
    }
}
