<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\ThemesContent\Actions\ReadContent;
use App\Domain\ThemesContent\Actions\ResolveLayout;
use App\Domain\ThemesContent\Actions\SaveContent;
use App\Domain\ThemesContent\Support\VisualTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff pages, articles and navigation (THEME-02, THEME-05, T065).
 *
 * Reading sits under `content.read`; creating records sits under
 * `content.write`. The workspace page only reflects those gates.
 */
final class ContentController
{
    public function show(Request $request, ReadContent $content, ResolveLayout $layouts): Response
    {
        Gate::authorize('content.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $pages = [];
        foreach ($content->pages() as $page) {
            $pages[] = $page + ['template_key' => $layouts->templateKey('page', $page['id'])];
        }

        return Inertia::render('ContentNavigation', [
            'pages' => $pages,
            'articles' => $content->articles(),
            'menus' => $content->menus(),
            'layoutOptions' => VisualTemplates::options('page'),
            'canWrite' => $staff->hasPermission('content.write'),
            'pageSaved' => session()->get('content_page_saved', false),
            'articleSaved' => session()->get('content_article_saved', false),
            'menuSaved' => session()->get('content_menu_saved', false),
            'layoutSaved' => session()->get('layout_saved', false),
        ]);
    }

    public function storePage(Request $request, SaveContent $save): RedirectResponse
    {
        return $this->persist($request, function (StaffAccount $staff, array $input) use ($save): void {
            $save->savePage($staff, $input);
        }, 'page', 'content_page_saved');
    }

    public function storeArticle(Request $request, SaveContent $save): RedirectResponse
    {
        return $this->persist($request, function (StaffAccount $staff, array $input) use ($save): void {
            $save->saveArticle($staff, $input);
        }, 'article', 'content_article_saved');
    }

    public function storeMenu(Request $request, SaveContent $save): RedirectResponse
    {
        return $this->persist($request, function (StaffAccount $staff, array $input) use ($save): void {
            $save->saveMenu($staff, $input);
        }, 'menu', 'content_menu_saved');
    }

    /**
     * @param  callable(StaffAccount, array<string, mixed>): void  $persist
     */
    private function persist(Request $request, callable $persist, string $key, string $flash): RedirectResponse
    {
        Gate::authorize('content.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $request->validate([
            $key => ['required', 'array'],
        ])[$key];
        try {
            $persist($staff, is_array($input) ? $input : []);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/content');
        }

        return redirect('/admin/content')->with($flash, true);
    }
}
