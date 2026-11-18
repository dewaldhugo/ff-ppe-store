<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Brands;

use App\Domain\Catalogue\Search\CatalogueSearch;
use App\Domain\Catalogue\Search\ListingQuery;
use App\Models\StorefrontBrand;
use Illuminate\Support\Str;
use Lunar\Models\Brand;

/**
 * The brands the storefront shows, built from the Lunar brands and the
 * storefront's own presentation records (storefront_brands).
 *
 * A Lunar brand that is a member of a storefront brand is shown under it
 * (Puma Safety and Puma Workwear under Puma). Any other Lunar brand, such as
 * one the ingestion module has just created, is shown on its own with a slug
 * from its name and a monogram, so a new vendor brand never goes missing.
 *
 * Product counts come from the search index (facet counts on `brand`), like
 * every other listing figure.
 */
final class BrandDirectory
{
    /** @var array<string, BrandTile>|null */
    private ?array $tiles = null;

    public function __construct(private readonly CatalogueSearch $search) {}

    /**
     * Brands with at least one published product, sorted by name.
     *
     * @return list<BrandTile>
     */
    public function withProducts(): array
    {
        return array_values(array_filter($this->all(), fn (BrandTile $tile): bool => $tile->productCount > 0));
    }

    /**
     * Every known brand by slug, whether or not it has products right now, so
     * a shared /brands/{slug} link shows an empty listing rather than a 404
     * while a brand is out of the catalogue.
     *
     * @return array<string, BrandTile>
     */
    public function all(): array
    {
        if ($this->tiles !== null) {
            return $this->tiles;
        }

        $counts = $this->productCounts();
        $tiles = [];
        $grouped = [];

        foreach (StorefrontBrand::query()->with('members')->get() as $brand) {
            $names = $brand->members->map(fn (Brand $member): string => (string) $member->name)->values()->all();

            foreach ($brand->members as $member) {
                $grouped[$member->id] = true;
            }

            $tiles[$brand->slug] = new BrandTile(
                slug: $brand->slug,
                name: $brand->name,
                memberNames: $names,
                logoUrl: $brand->logo_path === null ? null : asset($brand->logo_path),
                background: $brand->logo_background,
                productCount: array_sum(array_map(fn (string $name): int => $counts[$name] ?? 0, $names)),
            );
        }

        foreach (Brand::query()->whereNotIn('id', array_keys($grouped))->get() as $brand) {
            $name = (string) $brand->name;
            $slug = Str::slug($name);

            if ($slug === '' || isset($tiles[$slug])) {
                continue;
            }

            $tiles[$slug] = new BrandTile(
                slug: $slug,
                name: $name,
                memberNames: [$name],
                logoUrl: null,
                background: null,
                productCount: $counts[$name] ?? 0,
            );
        }

        uasort($tiles, fn (BrandTile $a, BrandTile $b): int => strnatcasecmp($a->name, $b->name));

        return $this->tiles = $tiles;
    }

    /**
     * The tiles for a list of slugs, or null when any slug is unknown.
     *
     * @param  list<string>  $slugs
     * @return list<BrandTile>|null
     */
    public function find(array $slugs): ?array
    {
        $all = $this->all();
        $found = [];

        foreach ($slugs as $slug) {
            if (! isset($all[$slug])) {
                return null;
            }

            $found[] = $all[$slug];
        }

        return $found;
    }

    /**
     * Published products per Lunar brand name.
     *
     * @return array<string, int>
     */
    private function productCounts(): array
    {
        $counts = [];

        foreach ($this->search->search(new ListingQuery(perPage: 1))->facets['brand'] ?? [] as $value) {
            $counts[$value->value] = $value->count;
        }

        return $counts;
    }
}
