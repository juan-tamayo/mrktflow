<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\Storefront\Actions\BuildMetadata;
use App\Domain\ThemesContent\Actions\ReadPublishedContent;
use App\Domain\ThemesContent\Actions\ResolveLayout;
use App\Domain\ThemesContent\Actions\SaveRedirect;
use App\Domain\ThemesContent\Rendering\DefaultThemeRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ContentPageController
{
    public function page(
        string $slug,
        SaveRedirect $redirects,
        ReadPublishedContent $published,
        ResolveLayout $layouts,
        BuildMetadata $metadata,
        DefaultThemeRenderer $renderer,
    ): View|RedirectResponse {
        return $this->show('page', 'pages/'.$slug, '/pages/'.$slug, $redirects, $published, $layouts, $metadata, $renderer);
    }

    public function article(
        string $slug,
        SaveRedirect $redirects,
        ReadPublishedContent $published,
        ResolveLayout $layouts,
        BuildMetadata $metadata,
        DefaultThemeRenderer $renderer,
    ): View|RedirectResponse {
        return $this->show('article', 'journal/'.$slug, '/journal/'.$slug, $redirects, $published, $layouts, $metadata, $renderer);
    }

    private function show(
        string $kind,
        string $documentPath,
        string $requestPath,
        SaveRedirect $redirects,
        ReadPublishedContent $published,
        ResolveLayout $layouts,
        BuildMetadata $metadata,
        DefaultThemeRenderer $renderer,
    ): View|RedirectResponse {
        $target = $redirects->resolve($requestPath);
        if ($target !== null) {
            try {
                $published->byPath($target);
            } catch (NotFoundHttpException) {
                abort(404);
            }

            return redirect($target, 301);
        }
        $document = $published->byPath($requestPath);
        if ($document['kind'] !== $kind || $document['path'] !== $documentPath) {
            abort(404);
        }
        $templateKey = $kind === 'page' ? $layouts->templateKey('page', $document['id']) : 'article.default';

        return $renderer->document(
            $kind,
            $document['title'],
            $document['excerpt'],
            $templateKey,
            $metadata->document($kind, $document['path'], $document['title'], $document['excerpt'] !== '' ? $document['excerpt'] : $document['title']),
        );
    }
}
