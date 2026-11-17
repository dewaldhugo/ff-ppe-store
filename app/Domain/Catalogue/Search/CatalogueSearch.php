<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Search;

use App\Domain\Catalogue\Options\GarmentSizes;
use Lunar\Models\Product;
use Meilisearch\Client;
use Meilisearch\Contracts\SearchQuery;

/**
 * The only way listings and facets are queried. Talks to Meilisearch
 * directly; nothing here filters through Eloquent.
 *
 * Facets are OR within a facet and AND across facets. Counts are
 * disjunctive: a facet's counts are calculated with that facet's own
 * selection removed, so the customer sees what picking another value would
 * give. Everything is sent in one multi-search request.
 */
final class CatalogueSearch
{
    private const string PRICE = '__price';

    public function __construct(private readonly Client $client) {}

    public function search(ListingQuery $query): ListingResult
    {
        $index = (new Product)->searchableAs();
        $priceField = ListingQuery::PRICE_FIELD;

        // The page of results, with counts for every facet. Counts for
        // facets that have a selection are replaced below.
        $main = (new SearchQuery)
            ->setIndexUid($index)
            ->setQuery($query->term)
            ->setFilter($this->filters($query))
            ->setFacets([...array_values(ListingQuery::FACETS), $priceField])
            ->setSort($query->sort->expressions())
            ->setAttributesToRetrieve(['id'])
            ->setHitsPerPage($query->perPage)
            ->setPage($query->page);

        $requests = [$main];
        $requestFor = [];

        foreach (ListingQuery::FACETS as $name => $field) {
            if ($query->selected($name) === []) {
                continue;
            }

            $requestFor[count($requests)] = $name;
            $requests[] = $this->countQuery($index, $query, $field, except: $name);
        }

        if ($query->hasPriceRange()) {
            $requestFor[count($requests)] = self::PRICE;
            $requests[] = $this->countQuery($index, $query, $priceField, except: self::PRICE);
        }

        $responses = $this->client->multiSearch($requests)['results'];

        $distribution = $responses[0]['facetDistribution'] ?? [];
        $priceStats = $responses[0]['facetStats'][$priceField] ?? null;

        foreach ($requestFor as $position => $name) {
            if ($name === self::PRICE) {
                $priceStats = $responses[$position]['facetStats'][$priceField] ?? null;

                continue;
            }

            $field = ListingQuery::FACETS[$name];
            $distribution[$field] = $responses[$position]['facetDistribution'][$field] ?? [];
        }

        $facets = [];

        foreach (ListingQuery::FACETS as $name => $field) {
            $facets[$name] = $this->facetValues($name, $distribution[$field] ?? [], $query->selected($name));
        }

        return new ListingResult(
            productIds: array_map(fn (array $hit): int => (int) $hit['id'], $responses[0]['hits']),
            total: (int) ($responses[0]['totalHits'] ?? 0),
            page: (int) ($responses[0]['page'] ?? $query->page),
            perPage: $query->perPage,
            lastPage: max(1, (int) ($responses[0]['totalPages'] ?? 1)),
            facets: $facets,
            priceFloor: $priceStats === null ? null : (int) $priceStats['min'],
            priceCeiling: $priceStats === null ? null : (int) $priceStats['max'],
        );
    }

    /**
     * A query that returns no hits, only the distribution of one field,
     * with one facet's (or the price range's) own filter left out.
     */
    private function countQuery(string $index, ListingQuery $query, string $field, string $except): SearchQuery
    {
        return (new SearchQuery)
            ->setIndexUid($index)
            ->setQuery($query->term)
            ->setFilter($this->filters($query, $except))
            ->setFacets([$field])
            ->setLimit(0);
    }

    /**
     * Meilisearch filter expressions, ANDed together.
     *
     * @return list<string>
     */
    private function filters(ListingQuery $query, ?string $except = null): array
    {
        // scout.soft_delete keeps a deleted product in the index, flagged
        // __soft_deleted = 1 with its old status, so both conditions are needed.
        $filters = ['status = "published"', '__soft_deleted = 0'];

        if ($query->collectionId !== null) {
            $filters[] = 'collection_ids = '.$query->collectionId;
        }

        if ($query->inStockOnly) {
            $filters[] = 'in_stock = true';
        }

        if ($except !== self::PRICE) {
            $priceField = ListingQuery::PRICE_FIELD;

            if ($query->priceMin !== null) {
                $filters[] = "{$priceField} >= {$query->priceMin}";
            }

            if ($query->priceMax !== null) {
                $filters[] = "{$priceField} <= {$query->priceMax}";
            }
        }

        foreach (ListingQuery::FACETS as $name => $field) {
            $values = $query->selected($name);

            if ($values !== [] && $name !== $except) {
                $filters[] = $this->in($field, $values);
            }
        }

        // With both a colour and a size chosen, the product must have that
        // exact combination in stock, not just the colour in some size and
        // the size in some other colour.
        $colours = $query->selected('colour');
        $sizes = $query->selected('size');

        if ($colours !== [] && $sizes !== [] && $except !== 'colour' && $except !== 'size') {
            $pairs = [];

            foreach ($colours as $colour) {
                foreach ($sizes as $size) {
                    $pairs[] = IndexSettings::variantPair($colour, $size);
                }
            }

            $filters[] = $this->in('variants_in_stock', $pairs);
        }

        return $filters;
    }

    /**
     * @param  list<string>  $values
     */
    private function in(string $field, array $values): string
    {
        $quoted = array_map(
            fn (string $value): string => '"'.addcslashes($value, '"\\').'"',
            $values,
        );

        return $field.' IN ['.implode(', ', $quoted).']';
    }

    /**
     * @param  array<string, int>  $distribution
     * @param  list<string>  $selected
     * @return list<FacetValue>
     */
    private function facetValues(string $facet, array $distribution, array $selected): array
    {
        // A selected value stays visible even when nothing matches it any
        // more, so the customer can untick it.
        foreach ($selected as $value) {
            $distribution[$value] ??= 0;
        }

        $values = array_map(strval(...), array_keys($distribution));

        if ($facet === 'size') {
            // Shoe sizes are plain numbers (`10`, `3.5`); garment sizes mix
            // the alphabetic scale, waist and chest measurements, and
            // vendor wording GarmentSizes does not recognise. A value that
            // is not a number sorts by GarmentSizes::sort() instead, so a
            // listing with both kinds still orders sensibly.
            $rank = fn (string $value): float => is_numeric($value) ? (float) $value : GarmentSizes::sort($value);
            usort($values, fn (string $a, string $b): int => $rank($a) <=> $rank($b));
        } else {
            sort($values, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return array_map(
            fn (string $value): FacetValue => new FacetValue($value, (int) $distribution[$value], in_array($value, $selected, true)),
            $values,
        );
    }
}
