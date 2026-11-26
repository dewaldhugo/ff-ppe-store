<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Catalogue\Urls\Redirects\RedirectResolver;
use Closure;
use Illuminate\Http\Request;
use Lunar\Models\Product;
use Lunar\Models\Url;
use Symfony\Component\HttpFoundation\Response;

/**
 * The database matches slugs case-insensitively, so /products/Some-Boot would
 * find the product. Compare the slug exactly instead, and send a request that
 * differs only by case to the canonical lowercase URL with a 301. Everything
 * else is left to the page, which answers 404 for an unknown or unpublished
 * product.
 *
 * A slug of a product that was merged into another (a vendor product line
 * listed several times, `vendorsync:merge-splits`) has a row in
 * `url_redirects`, and gets a 301 to the product that took its place.
 */
final class ResolveProductSlug
{
    public function __construct(private readonly RedirectResolver $redirects) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) $request->route('slug');

        $moved = $this->redirects->resolve(route('product', $slug, false));

        if ($moved !== null) {
            $query = $request->getQueryString();

            return redirect()->to($moved.($query === null ? '' : '?'.$query), 301);
        }

        $slugs = Url::query()
            ->where('element_type', Product::morphName())
            ->where('slug', $slug)
            ->pluck('slug');

        if ($slugs->isNotEmpty() && ! in_array($slug, $slugs->all(), true)) {
            $query = $request->getQueryString();

            return redirect()->to(route('product', $slugs->first()).($query === null ? '' : '?'.$query), 301);
        }

        return $next($request);
    }
}
