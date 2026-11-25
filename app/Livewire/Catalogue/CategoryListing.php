<?php

declare(strict_types=1);

namespace App\Livewire\Catalogue;

use App\Domain\Catalogue\Features\FeatureLabels;
use App\Domain\Catalogue\Options\ColourSwatch;
use App\Domain\Catalogue\Search\CatalogueSearch;
use App\Domain\Catalogue\Search\FacetValue;
use App\Domain\Catalogue\Search\ListingQuery;
use App\Domain\Catalogue\Search\ListingSort;
use App\Domain\Catalogue\Urls\CategoryUrl;
use App\Domain\Pricing\PriceDisplay;
use App\Http\Middleware\ResolveBrandSlugs;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Lunar\Models\Collection;
use Lunar\Models\Product;
use Lunar\Models\ProductOptionValue;

/**
 * Category listing (/shop/{path}) and search results (/search).
 *
 * All filter state lives in the query string, so a filtered listing can be
 * shared, bookmarked and indexed. Products and facet counts come from
 * CatalogueSearch only; Eloquent is used just to load the products on the
 * current page for display.
 */
final class CategoryListing extends Component
{
    #[Locked]
    public ?int $collectionId = null;

    /**
     * Set on /brands/{slugs} only: the URL slugs, the Lunar brand names the
     * listing is limited to, and the page heading.
     *
     * @var list<string>
     */
    #[Locked]
    public array $brandSlugs = [];

    /** @var ?list<string> */
    #[Locked]
    public ?array $brandScope = null;

    #[Locked]
    public string $brandHeading = '';

    #[Url(except: '')]
    public string $q = '';

    /** @var list<string> */
    #[Url]
    public array $brand = [];

    /** @var list<string> */
    #[Url]
    public array $safety_class = [];

    /** @var list<string> */
    #[Url]
    public array $features = [];

    /** @var list<string> */
    #[Url]
    public array $size = [];

    /** @var list<string> */
    #[Url]
    public array $colour = [];

    /** @var list<string> */
    #[Url]
    public array $gender = [];

    /** @var list<string> */
    #[Url]
    public array $industry = [];

    #[Url(except: '')]
    public string $price_min = '';

    #[Url(except: '')]
    public string $price_max = '';

    #[Url(except: false)]
    public bool $in_stock = false;

    #[Url(except: 'relevance')]
    public string $sort = 'relevance';

    #[Url(except: 1)]
    public int $page = 1;

    /**
     * The ResolveCategoryPath middleware has already matched the URL path to a
     * collection (or redirected, or answered 404) and passes its id in. The
     * search page has none.
     *
     * On a brand page ResolveBrandSlugs has resolved the brands and left them
     * in a request attribute. They are not route parameters on purpose:
     * Livewire also passes route parameters to mount() by position, so any
     * extra mount() argument would shift collectionId on every listing.
     */
    public function mount(?int $collectionId = null): void
    {
        $this->collectionId = $collectionId;

        $brands = request()->attributes->get(ResolveBrandSlugs::ATTRIBUTE);

        if (is_array($brands)) {
            $this->brandSlugs = $brands['slugs'];
            $this->brandScope = $brands['scope'];
            $this->brandHeading = $brands['heading'];
        }
    }

    /**
     * Any change other than paging starts again from the first page.
     */
    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->page = 1;
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['brand', 'safety_class', 'features', 'size', 'colour', 'gender', 'industry', 'price_min', 'price_max', 'in_stock', 'page']);
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function render(CatalogueSearch $search, PriceDisplay $priceDisplay, CategoryUrl $categoryUrl): View
    {
        $showPrices = $priceDisplay->visible();

        // Guests must not be able to learn prices by filtering or sorting on them,
        // whether through the URL or a later Livewire update. Render runs after both.
        if (! $showPrices) {
            $this->dropPriceState();
        }

        $query = ListingQuery::fromArray($this->state(), $this->collectionId, $this->brandScope);
        $result = $search->search($query);

        $collection = $this->collectionId === null ? null : Collection::query()->find($this->collectionId);

        $heading = match (true) {
            $collection !== null => (string) $collection->translateAttribute('name'),
            $this->brandSlugs !== [] => $this->brandHeading,
            $query->term !== '' => 'Search results for "'.$query->term.'"',
            default => 'All products',
        };

        return view('livewire.catalogue.category-listing', [
            'heading' => $heading,
            'breadcrumbs' => $this->breadcrumbs($collection, $categoryUrl),
            'result' => $result,
            'products' => $this->products($result->productIds),
            'showPrices' => $showPrices,
            'sorts' => array_values(array_filter(
                ListingSort::cases(),
                fn (ListingSort $sort): bool => $showPrices || ! $sort->isPriceSort(),
            )),
            'facetGroups' => $this->facetGroups($result->facets),
            'hasFilters' => $query->facets !== [] || $query->hasPriceRange() || $query->inStockOnly,
        ])->layout('components.layouts.storefront', [
            'title' => $heading,
            'canonical' => $this->canonical($collection, $categoryUrl),
        ]);
    }

    private function dropPriceState(): void
    {
        $this->price_min = '';
        $this->price_max = '';

        if (ListingSort::tryFrom($this->sort)?->isPriceSort() === true) {
            $this->sort = ListingSort::Relevance->value;
        }
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private function breadcrumbs(?Collection $collection, CategoryUrl $categoryUrl): array
    {
        if ($this->brandSlugs !== []) {
            return [['name' => 'Brands', 'url' => route('brands')]];
        }

        $crumbs = [];

        foreach ($collection?->ancestors ?? [] as $ancestor) {
            $url = $categoryUrl->for($ancestor);

            if ($url !== null) {
                $crumbs[] = ['name' => (string) $ancestor->translateAttribute('name'), 'url' => $url];
            }
        }

        return $crumbs;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return [
            'q' => $this->q,
            'brand' => $this->brand,
            'safety_class' => $this->safety_class,
            'features' => $this->features,
            'size' => $this->size,
            'colour' => $this->colour,
            'gender' => $this->gender,
            'industry' => $this->industry,
            'price_min' => $this->price_min,
            'price_max' => $this->price_max,
            'in_stock' => $this->in_stock,
            'sort' => $this->sort,
            'page' => $this->page,
        ];
    }

    /**
     * A category's canonical is always the unfiltered category URL: filters and
     * sorting never create a new canonical page. Only paging does, so page 2
     * points at itself. Search keeps its filters in the canonical.
     */
    private function canonical(?Collection $collection, CategoryUrl $categoryUrl): string
    {
        // A brand page follows the category rule: filters never make a new canonical page.
        if ($this->brandSlugs !== []) {
            $url = route('brands.show', implode(',', $this->brandSlugs));

            return $this->page > 1 && $this->onlyPaging() ? $url.'?page='.$this->page : $url;
        }

        if ($collection !== null) {
            $url = $categoryUrl->for($collection);

            if ($url !== null) {
                $paged = $this->page > 1 && $this->onlyPaging();

                return $paged ? $url.'?page='.$this->page : $url;
            }
        }

        $params = array_filter(
            $this->state(),
            fn (mixed $value): bool => $value !== '' && $value !== [] && $value !== false && $value !== 'relevance' && $value !== 1,
        );

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                sort($value);
                $params[$key] = $value;
            }
        }

        if (isset($params['in_stock'])) {
            $params['in_stock'] = 1;
        }

        return route('search', $params);
    }

    /**
     * Whether the page is a plain page of the category: nothing in the query
     * string except `page`, and no filter or sort state.
     */
    private function onlyPaging(): bool
    {
        $other = array_diff_key(request()->query(), ['page' => true]);

        $filtered = $this->brand !== [] || $this->safety_class !== [] || $this->features !== []
            || $this->size !== [] || $this->colour !== [] || $this->gender !== []
            || $this->industry !== []
            || $this->price_min !== '' || $this->price_max !== '' || $this->in_stock
            || $this->sort !== 'relevance' || $this->q !== '';

        return $other === [] && ! $filtered;
    }

    /**
     * @param  list<int>  $ids
     * @return list<Product>
     */
    private function products(array $ids): array
    {
        $products = Product::query()
            ->whereIn('id', $ids)
            ->with(['brand', 'defaultUrl', 'thumbnail', 'variants.prices', 'variants.values.option'])
            ->get()
            ->keyBy('id');

        $ordered = [];

        foreach ($ids as $id) {
            if (isset($products[$id])) {
                $ordered[] = $products[$id];
            }
        }

        return $ordered;
    }

    /**
     * Facets ready for the view: a title, the bound property, and for each
     * value a customer-facing label.
     *
     * @param  array<string, list<FacetValue>>  $facets
     * @return list<array{name: string, title: string, values: list<array{value: string, label: string, tooltip: ?string, hexes: list<string>, count: int, selected: bool}>}>
     */
    private function facetGroups(array $facets): array
    {
        $titles = [
            'brand' => 'Brand',
            'safety_class' => 'Safety class',
            'features' => 'Features',
            'size' => 'UK size',
            'colour' => 'Colour',
            'gender' => 'Gender',
            'industry' => 'Industry',
        ];

        $colours = $this->colours();
        $groups = [];

        foreach ($titles as $name => $title) {
            $values = [];

            foreach ($facets[$name] ?? [] as $value) {
                $values[] = [
                    'value' => $value->value,
                    'label' => match ($name) {
                        'features' => FeatureLabels::label($value->value),
                        'colour' => $colours[$value->value]['name'] ?? Str::headline($value->value),
                        default => $value->value,
                    },
                    'tooltip' => $name === 'features' ? FeatureLabels::tooltip($value->value) : null,
                    'hexes' => $name === 'colour'
                        ? ColourSwatch::hexes($colours[$value->value]['name'] ?? $value->value, $colours[$value->value]['hex'] ?? null)
                        : [],
                    'count' => $value->count,
                    'selected' => $value->selected,
                ];
            }

            if ($values !== []) {
                $groups[] = ['name' => $name, 'title' => $title, 'values' => $values];
            }
        }

        return $groups;
    }

    /**
     * Colour names and hex values by the slug used in the search index.
     *
     * @return array<string, array{name: string, hex: ?string}>
     */
    private function colours(): array
    {
        $colours = [];

        $values = ProductOptionValue::query()
            ->whereHas('option', fn ($query) => $query->where('handle', 'colour'))
            ->get();

        foreach ($values as $value) {
            $name = (string) $value->translate('name');
            $colours[Str::slug($name)] = ['name' => $name, 'hex' => $value->meta['hex'] ?? null];
        }

        return $colours;
    }
}
