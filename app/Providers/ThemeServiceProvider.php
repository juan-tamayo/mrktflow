<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class ThemeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Reviewed application code only; requests and imported content cannot choose this path.
        View::addNamespace('default-theme', base_path('themes/default/views'));
    }
}
