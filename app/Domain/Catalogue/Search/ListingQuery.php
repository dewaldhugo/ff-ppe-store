<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Search;

/**
 * Everything that defines one listing request: where it is (collection or
 * search term), the selected facets, price range, sort and page.
 *
 * Built from the request query string, so a filtered URL can be shared.
 */
final readonly class ListingQuery
{
    /**
     * The index field every price is filtered and sorted on. The storefront
     * shows ex VAT prices only, and the index holds no inc VAT figure.
     */
    public const string PRICE_FIELD = 'price_min_ex_vat';

    public const int PER_PAGE = 24;

    /**
     * Facet name in the URL => field in the search index.
     */
    public const array FACETS = [
        'brand' => 'brand',
        'safety_class' => 'safety_class',
        'features' => 'features',
        'size' => 'sizes_in_stock',
        'colour' => 'colours_in_stock',
        'gender' => 'gender',
    ];

    /**
     * @param  array<string, list<string>>  $facets  Selected values by facet name.
     * @param  ?int  $priceMin  Pence, ex VAT.
     * @param  ?int  $priceMax  Pence, ex VAT.
     * @param  ?list<string>  $brandScope  Lunar brand names a brand page is limited to; null for no limit, [] for nothing.
     */
    public function __construct(
        public ?int $collectionId = null,
        public string $term = '',
        public array $facets = [],
        public ?int $priceMin = null,
        public ?int $priceMax = null,
        public bool $inStockOnly = false,
        public ListingSort $sort = ListingSort::Relevance,
        public int $page = 1,
        public int $perPage = self::PER_PAGE,
        public ?array $brandScope = null,
    ) {}

    /**
     * Build from query string input. Unknown facets, blank values and
     * malformed numbers are dropped rather than rejected, so a mangled URL
     * still shows a listing.
     *
     * Query string shape: brand[]=GBS&size[]=9&colour[]=navy&price_min=20
     * &price_max=80&in_stock=1&sort=price-asc&page=2&q=boots. Prices are in
     * whole pounds.
     *
     * @param  array<string, mixed>  $input
     * @param  ?list<string>  $brandScope
     */
    public static function fromArray(array $input, ?int $collectionId = null, ?array $brandScope = null): self
    {
        $facets = [];

        foreach (array_keys(self::FACETS) as $name) {
            $values = self::cleanList($input[$name] ?? []);

            if ($values !== []) {
                $facets[$name] = $values;
            }
        }

        return new self(
            collectionId: $collectionId,
            brandScope: $brandScope,
            term: is_string($input['q'] ?? null) ? trim($input['q']) : '',
            facets: $facets,
            priceMin: self::pounds($input['price_min'] ?? null),
            priceMax: self::pounds($input['price_max'] ?? null),
            inStockOnly: filter_var($input['in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN),
            sort: ListingSort::tryFrom(is_string($input['sort'] ?? null) ? $input['sort'] : '') ?? ListingSort::Relevance,
            page: max(1, (int) (is_numeric($input['page'] ?? null) ? $input['page'] : 1)),
        );
    }

    /**
     * @return list<string>
     */
    public function selected(string $facet): array
    {
        return $this->facets[$facet] ?? [];
    }

    public function hasPriceRange(): bool
    {
        return $this->priceMin !== null || $this->priceMax !== null;
    }

    /**
     * @return list<string>
     */
    private static function cleanList(mixed $value): array
    {
        $values = [];

        foreach (is_array($value) ? $value : [$value] as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $values[] = trim((string) $item);
            }
        }

        return array_values(array_unique($values));
    }

    private static function pounds(mixed $value): ?int
    {
        if (! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return (int) round((float) $value * 100);
    }
}
