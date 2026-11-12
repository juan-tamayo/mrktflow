<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\ThemesContent\Actions\ReadSectionDraft;
use App\Domain\ThemesContent\Actions\RenderSections;
use App\Domain\ThemesContent\Support\ContentRevisions;
use App\Domain\ThemesContent\Support\SectionDraftTargets;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Authorized draft preview (THEME-03, T067). Staff with content.read can
 * inspect a draft revision; the response is noindex/no-store and never
 * publishes. The storefront homepage stays closed.
 */
final class PreviewController
{
    public function page(string $id): View
    {
        Gate::authorize('content.read');

        return $this->document(ContentRevisions::PAGE, 'pages', $id);
    }

    public function article(string $id): View
    {
        Gate::authorize('content.read');

        return $this->document(ContentRevisions::ARTICLE, 'articles', $id);
    }

    public function sections(Request $request, ReadSectionDraft $read, RenderSections $render): View
    {
        Gate::authorize('content.read');
        $data = $request->validate([
            'page' => ['required', 'string', 'max:64'],
            'slot' => ['required', 'string', 'max:64'],
        ]);
        if (! SectionDraftTargets::isEditable($data['page'], $data['slot'])) {
            throw new InvalidArgumentException("Page [{$data['page']}] has no editable section slot [{$data['slot']}].");
        }
        $draft = $read->read($data['page'], $data['slot']);
        $html = $draft['sections'] === [] ? '' : $render->render($data['page'], $data['slot'], $draft['sections'])->render();

        return view('default-theme::content-preview', [
            'pageKey' => $data['page'],
            'pageTitle' => $data['page'].' '.$data['slot'].' draft',
            'preview' => true,
            'bodyHtml' => $html,
        ]);
    }

    private function document(string $kind, string $table, string $id): View
    {
        $row = DB::table($table)->where('id', $id)->first();
        abort_unless($row !== null, 404);
        $payload = ContentRevisions::payload($kind, $id, (int) $row->draft_revision);
        abort_unless(is_array($payload), 404);

        return view('default-theme::content-preview', [
            'pageKey' => 'home',
            'pageTitle' => (string) ($payload['title'] ?? 'Content preview'),
            'excerpt' => (string) ($payload['excerpt'] ?? ''),
            'preview' => true,
        ]);
    }
}
