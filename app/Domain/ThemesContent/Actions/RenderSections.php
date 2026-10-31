<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Actions;

use App\Domain\Storefront\Actions\ComposePage;
use Illuminate\Contracts\View\View;

/**
 * Renders a section document into a storefront page (THEME-02, T063b).
 *
 * The payload is validated through ValidateSections first — an invalid
 * payload throws and nothing renders — then the sections view is composed
 * into the page through T062's ComposePage, assigned to a named theme
 * slot. Assigning sections to a platform slot (a commerce action or the
 * page chrome) or to a slot the page does not have fails composition
 * exactly like any other slot assignment. The view receives only the
 * validated sections with their resolved binding data.
 */
final class RenderSections
{
    public function __construct(
        private readonly ValidateSections $validate,
        private readonly ComposePage $compose,
    ) {}

    /**
     * @param  list<mixed>  $sections
     */
    public function render(string $page, string $slot, array $sections): View
    {
        $validated = $this->validate->validate($sections);
        $composed = $this->compose->compose($page, [$slot => 'default-theme::sections']);

        return view('default-theme::sections', [
            'sections' => $validated,
            'composedPage' => $composed,
            'sectionSlot' => $slot,
        ]);
    }
}
