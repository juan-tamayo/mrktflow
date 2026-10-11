<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class RequireInstalledStore
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 404);

        return $next($request);
    }
}
