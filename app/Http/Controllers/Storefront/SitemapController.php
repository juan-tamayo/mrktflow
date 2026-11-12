<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\Storefront\Actions\BuildSitemap;
use Symfony\Component\HttpFoundation\Response;

final class SitemapController
{
    public function show(BuildSitemap $sitemap): Response
    {
        return response($sitemap->xml(), Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
