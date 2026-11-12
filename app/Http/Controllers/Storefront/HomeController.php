<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use Illuminate\Http\Response;

final class HomeController
{
    /**
     * The shop homepage stays closed until the storefront-open slice.
     * Installation, catalog pages and CMS records must not open GET /.
     */
    public function show(): Response
    {
        return response()->view('storefront.closed', [], 503)->header('Retry-After', '3600');
    }
}
