<?php

namespace Makermind\Erd4dev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeErd
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local')) {
            Gate::authorize('erd.access');
        }

        if (app()->environment('local') && ! Schema::hasTable(config('erd.table'))) {
            (require __DIR__.'/../../../database/migrations/2026_01_01_000000_create_erd_diagrams_table.php')->up();
        }

        return $next($request);
    }
}
