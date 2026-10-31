<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Actions\SearchCatalog;
use App\Domain\Storefront\Actions\ComposePage;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class ThemeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Reviewed application code only; requests and imported content cannot choose this path.
        View::addNamespace('default-theme', base_path('themes/default/views'));

        // Shop chrome shared by every storefront page: the store's own name
        // and its published top-level categories for the masthead and footer.
        View::composer('default-theme::*', function ($view): void {
            $shopName = 'MRKTFlow';
            $shopCategories = [];
            try {
                if (DB::table('installations')->where('id', 1)->value('status') === 'complete') {
                    $values = DB::table('store_settings')->where('id', 1)->value('values');
                    $settings = is_string($values) ? json_decode($values, true) : null;
                    if (is_array($settings) && is_string($settings['name'] ?? null) && $settings['name'] !== '') {
                        $shopName = $settings['name'];
                    }
                    foreach (app(SearchCatalog::class)->search([])['categories'] as $category) {
                        if (! str_contains($category['path'], '/')) {
                            $shopCategories[] = $category;
                        }
                    }
                }
            } catch (Throwable) {
                // Chrome data is best-effort; the page itself must still render.
            }
            $view->with('shopName', $shopName)->with('shopCategories', $shopCategories);
        });

        // Every themed page renders through the one layout; composing its
        // page key here runs the SystemSlots contract on each render, so a
        // slot assignment supplied as view data (slotViews) that names a
        // platform slot or an unknown slot is refused instead of rendered.
        View::composer('default-theme::layout', function (ViewContract $view): void {
            $data = $view->getData();
            $pageKey = $data['pageKey'] ?? null;
            if (! is_string($pageKey) || $pageKey === '') {
                return;
            }
            $slotViews = $data['slotViews'] ?? [];
            $view->with('composedPage', app(ComposePage::class)->compose(
                $pageKey,
                is_array($slotViews) ? $slotViews : [],
            ));
        });
    }
}
