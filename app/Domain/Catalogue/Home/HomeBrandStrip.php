<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Home;

use App\Domain\Catalogue\Brands\BrandDirectory;
use App\Domain\Catalogue\Brands\BrandTile;
use Throwable;

/**
 * The brands in the home page's "Shop by brand" strip: the same brands as /brands (BrandDirectory), most
 * products first, cut to `homepage.brands.limit`. Product counts come from the search index, so when the
 * index cannot be reached the strip is left out (and the error reported) rather than taking the home page down.
 */
final class HomeBrandStrip
{
    public function __construct(private readonly BrandDirectory $directory) {}

    /**
     * @return list<BrandTile>
     */
    public function tiles(): array
    {
        try {
            $tiles = $this->directory->withProducts();
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        return array_slice($tiles, 0, max(0, (int) config('homepage.brands.limit', 12)));
    }
}
