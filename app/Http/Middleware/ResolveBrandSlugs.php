<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Catalogue\Brands\BrandDirectory;
use App\Domain\Catalogue\Brands\BrandTile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves /brands/{brands} before the listing mounts. {brands} is one slug
 * or several joined by commas (/brands/amblers,safety-jogger).
 *
 * One selection has one URL: slugs are lower-cased, de-duplicated and sorted,
 * and any other spelling gets a 301 to that form. An unknown slug is a 404.
 */
final class ResolveBrandSlugs
{
    public const string ATTRIBUTE = 'brand_page';

    public function __construct(private readonly BrandDirectory $directory) {}

    public function handle(Request $request, Closure $next): Response
    {
        $given = (string) $request->route('brands');
        $slugs = array_values(array_unique(array_filter(explode(',', strtolower($given)), fn (string $slug): bool => $slug !== '')));
        sort($slugs);

        $tiles = $slugs === [] ? null : $this->directory->find($slugs);

        abort_if($tiles === null, 404);

        $canonical = implode(',', $slugs);

        if ($canonical !== $given) {
            $query = $request->getQueryString();

            return redirect()->to(route('brands.show', $canonical).($query === null ? '' : '?'.$query), 301);
        }

        // The listing reads the brands from a request attribute, not from route
        // parameters: Livewire passes route parameters to mount() by position too.
        $request->route()->forgetParameter('brands');
        $request->attributes->set(self::ATTRIBUTE, [
            'slugs' => $slugs,
            'scope' => array_values(array_unique(array_merge(...array_map(fn (BrandTile $tile): array => $tile->memberNames, $tiles)))),
            'heading' => implode(', ', array_map(fn (BrandTile $tile): string => $tile->name, $tiles)),
        ]);

        return $next($request);
    }
}
