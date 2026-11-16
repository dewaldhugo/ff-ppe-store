<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Catalogue\Urls\CategoryPathResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves /shop/{path} before the listing component mounts, so a wrong or
 * retired path gets a real 301 (a Livewire mount can only send a 302) and an
 * unknown one a 404. The component receives the resolved collection id.
 */
final class ResolveCategoryPath
{
    public function __construct(private readonly CategoryPathResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $match = $this->resolver->resolve((string) $request->route('path'));

        abort_if($match === null, 404);

        if ($match->redirectTo !== null) {
            $query = $request->getQueryString();

            return redirect()->to(url($match->redirectTo).($query === null ? '' : '?'.$query), 301);
        }

        $route = $request->route();
        $route->forgetParameter('path');
        $route->setParameter('collectionId', $match->collectionId);

        return $next($request);
    }
}
