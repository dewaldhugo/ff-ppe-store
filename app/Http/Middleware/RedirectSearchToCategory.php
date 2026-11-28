<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Catalogue\Menu\CategoryMenu;
use App\Domain\Catalogue\Urls\CategoryUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a search box carry a category: `/search?q=boots&category=safety-footwear` goes to that category's own page with the search in it
 * (`/shop/safety-footwear?q=boots`, which already filters by `q`), so the category drop-down on the home page works without JavaScript.
 *
 * - A category the catalogue has (a top-level one or one of its children, by contract handle) is a 302 to its page, with `q` kept.
 * - An empty `category` (the "All categories" option) is a 302 to `/search` without it, so the address stays clean.
 * - An unknown `category` is dropped the same way: the search runs across everything.
 *
 * A request with no `category` at all is not touched. The redirect is temporary: it depends on what the form sent, not on a page that
 * moved.
 */
final class RedirectSearchToCategory
{
    public function __construct(
        private readonly CategoryMenu $menu,
        private readonly CategoryUrl $urls,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->query->has('category')) {
            return $next($request);
        }

        $handle = $request->query('category');
        $term = trim((string) $request->query('q', ''));

        if (is_string($handle) && $handle !== '' && in_array($handle, $this->handles(), true)) {
            $url = $this->urls->forHandle($handle, $term === '' ? [] : ['q' => $term]);

            if ($url !== null) {
                return redirect($url);
            }
        }

        // No category, or one that is not there: the plain search, without the parameter.
        $params = $request->query->all();
        unset($params['category']);

        return redirect()->route('search', $params);
    }

    /**
     * The contract handles of the top-level categories and their children, from the menu.
     *
     * @return list<string>
     */
    private function handles(): array
    {
        $handles = [];

        foreach ($this->menu->tree() as $category) {
            $handles[] = $category['handle'];

            foreach ($category['children'] as $child) {
                $handles[] = $child['handle'];
            }
        }

        return array_values(array_filter($handles, is_string(...)));
    }
}
