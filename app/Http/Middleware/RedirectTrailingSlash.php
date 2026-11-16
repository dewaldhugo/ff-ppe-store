<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends /shop/ to /shop with a 301. Applied to the storefront routes only
 * (see routes/web.php), so the admin panel, Livewire, Horizon and any future
 * webhook route are untouched. GET and HEAD only: a redirect would drop a
 * POST body.
 */
final class RedirectTrailingSlash
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();

        if (in_array($request->method(), ['GET', 'HEAD'], true) && $path !== '/' && str_ends_with($path, '/')) {
            $query = $request->getQueryString();

            // url() already drops the trailing slash and the query string.
            return redirect()->to($request->url().($query === null ? '' : '?'.$query), 301);
        }

        return $next($request);
    }
}
