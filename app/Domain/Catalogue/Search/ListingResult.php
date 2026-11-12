<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Search;

final readonly class ListingResult
{
    /**
     * @param  list<int>  $productIds  Product IDs for the current page, in result order.
     * @param  array<string, list<FacetValue>>  $facets  Values by facet name (see ListingQuery::FACETS).
     * @param  ?int  $priceFloor  Lowest price (pence, in the query's VAT mode) among results ignoring the price filter.
     * @param  ?int  $priceCeiling  Highest such price.
     */
    public function __construct(
        public array $productIds,
        public int $total,
        public int $page,
        public int $perPage,
        public int $lastPage,
        public array $facets,
        public ?int $priceFloor,
        public ?int $priceCeiling,
    ) {}

    /**
     * @return array<string, int> Count by value.
     */
    public function counts(string $facet): array
    {
        $counts = [];

        foreach ($this->facets[$facet] ?? [] as $value) {
            $counts[$value->value] = $value->count;
        }

        return $counts;
    }
}
