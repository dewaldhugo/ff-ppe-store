<?php

declare(strict_types=1);

namespace App\Livewire\Catalogue;

use App\Domain\Cart\CartRejected;
use App\Domain\Cart\CustomerCart;
use App\Domain\Catalogue\Brands\ProductBrandLink;
use App\Domain\Catalogue\Display\CountryName;
use App\Domain\Catalogue\Display\ProductPictograms;
use App\Domain\Catalogue\Display\SafetyStandardPanel;
use App\Domain\Catalogue\Media\ProductMediaDefinitions;
use App\Domain\Catalogue\Related\RelatedProductIds;
use App\Domain\Catalogue\Seo\ProductStructuredData;
use App\Domain\Catalogue\Urls\ProductBreadcrumbs;
use App\Domain\Catalogue\Variants\VariantSelection;
use App\Domain\Pricing\DisplayPrice;
use App\Domain\Pricing\PriceDisplay;
use App\Domain\Pricing\PriceVisibility;
use App\Support\CartCount;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Lunar\Models\Url as LunarUrl;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Product detail page (/products/{slug}). The selection rules themselves live in
 * VariantSelection; this component only holds the customer's choices.
 */
final class ProductPage extends Component
{
    /** Show "Only N left" at or below this quantity. */
    private const int LOW_STOCK = 5;

    private const array SPECIFICATION = [
        'toecap' => 'Toecap',
        'midsole' => 'Midsole',
        'outsole' => 'Outsole',
        'upper' => 'Upper',
        'lining' => 'Lining',
        'cut' => 'Cut',
        'fastening' => 'Fastening',
        'country_of_origin' => 'Country of origin',
    ];

    #[Locked]
    public int $productId;

    #[Locked]
    public string $slug;

    /** The chosen colour, kept in the query string (?colour=navy). */
    #[Url(except: '')]
    public string $colour = '';

    /**
     * Choices for the other options, by option handle.
     *
     * @var array<string, string>
     */
    public array $choices = [];

    /** Handle of the option the customer still has to choose, once they try to add to cart. */
    public ?string $missingOption = null;

    /**
     * The quantity as typed. Empty until the customer enters one: add to
     * cart stays disabled until then. A string, so a cleared field or a
     * stray character never breaks the page.
     */
    public string $quantity = '';

    public bool $added = false;

    /** How many went into the cart on the last add, for the confirmation. */
    public int $addedQuantity = 0;

    /** Why the cart refused the last add (stock, or the item was withdrawn). */
    public ?string $cartError = null;

    private ?Product $product = null;

    /**
     * Ids of the related products, chosen once on mount so choosing a colour or size does not search again.
     *
     * @var list<int>
     */
    #[Locked]
    public array $relatedIds = [];

    public function mount(string $slug, RelatedProductIds $relatedProducts): void
    {
        $url = LunarUrl::query()
            ->where('element_type', Product::morphName())
            ->where('slug', $slug)
            ->first();

        $product = $url === null ? null : Product::query()->find($url->element_id);

        abort_if($product === null || $product->status !== 'published', 404);

        $this->productId = $product->id;
        $this->slug = $slug;
        $this->relatedIds = $relatedProducts->for($product);

        $this->sync($this->selection());
    }

    public function choose(string $handle, string $key, PriceVisibility $visibility): void
    {
        // Guests can look at the sizes but not pick one; colours stay selectable.
        if (! $visibility->visible() && str_ends_with($handle, '_size')) {
            return;
        }

        $selection = $this->selection()->select($handle, $key);

        $this->sync($selection);
        $this->missingOption = null;
        $this->added = false;
        $this->cartError = null;
    }

    public function updatedQuantity(): void
    {
        $this->added = false;
        $this->cartError = null;
    }

    public function addToCart(PriceVisibility $visibility, CustomerCart $customerCart): void
    {
        $selection = $this->selection();
        $this->added = false;
        $this->cartError = null;

        // Guests have no quantity field or button; refuse a hand-made update too.
        if (! $visibility->visible()) {
            return;
        }

        if (! $selection->isComplete()) {
            $this->missingOption = $selection->missing()[0];

            return;
        }

        $this->missingOption = null;

        if (! $selection->canAddToCart()) {
            return;
        }

        $quantity = $this->quantityValue();
        $variant = $selection->variant();

        if ($quantity === null || $variant === null || $quantity > $variant->stock) {
            return;
        }

        // Lunar validates the stock again, on the quantity already in the cart plus this one.
        try {
            $customerCart->add($variant, $quantity);
        } catch (CartRejected $rejected) {
            $this->cartError = $rejected->getMessage();

            return;
        }

        $this->added = true;
        $this->addedQuantity = $quantity;

        // The header badge listens for this.
        $this->dispatch('cart-updated', count: CartCount::get());
    }

    public function render(PriceDisplay $priceDisplay, ProductStructuredData $structuredData, ProductBreadcrumbs $breadcrumbs): View
    {
        $product = $this->product();
        $selection = $this->selection();
        $variant = $selection->variant();
        $canonical = route('product', $this->slug);

        return view('livewire.catalogue.product-page', [
            'product' => $product,
            'brand' => $this->brandLink($product),
            'breadcrumbs' => $breadcrumbs->for($product),
            'selection' => $selection,
            'options' => $selection->options(),
            'variant' => $variant,
            'images' => $this->images($product, $selection),
            'price' => $this->price($priceDisplay, $selection, $variant),
            'showPrices' => $priceDisplay->visible(),
            'pricesVary' => $variant === null && $this->pricesVary($selection),
            'stockMessage' => $this->stockMessage($selection, $variant),
            // Enabled only once every option is chosen, that variant is in
            // stock, and a quantity it can cover has been entered.
            'canAdd' => $variant !== null && $variant->stock > 0
                && $this->quantityValue() !== null && $this->quantityValue() <= $variant->stock,
            'quantityError' => $this->quantityError($variant),
            'maxQuantity' => $variant !== null && $variant->stock > 0 ? $variant->stock : null,
            'lowStock' => $variant !== null && $variant->stock > 0 && $variant->stock <= self::LOW_STOCK,
            'addLabel' => $this->addLabel($selection, $variant),
            'euSizes' => $this->euSizes($product, $selection),
            'specification' => $this->specification($product),
            'safetyStandard' => app(SafetyStandardPanel::class)->for($product),
            'industries' => array_values(array_filter(array_map(fn (mixed $industry): string => trim((string) $industry), (array) $product->attr('industries')), filled(...))),
            'pictograms' => app(ProductPictograms::class)->for($product),
            'productSheet' => $this->productSheet($product, $selection),
            'paragraphs' => preg_split('/\R{2,}|\R/', trim((string) $product->translateAttribute('description')), flags: PREG_SPLIT_NO_EMPTY) ?: [],
            'videoUrl' => $this->videoUrl($product),
            'related' => $this->related(),
            'missingPrompt' => $this->missingOption === null ? null : $selection->promptFor($this->missingOption),
        ])->layout('components.layouts.storefront', [
            'title' => $this->pageTitle($product),
            'description' => $product->translateAttribute('short_description') ?: null,
            'canonical' => $canonical,
            'head' => new HtmlString(
                '<script type="application/ld+json">'.$structuredData->toJson($product, $canonical).'</script>'
            ),
        ]);
    }

    /**
     * The brand's name, logo and page for the top of the product info.
     *
     * @return ?array{name: string, slug: string, logoUrl: ?string}
     */
    private function brandLink(Product $product): ?array
    {
        return app(ProductBrandLink::class)->for($product);
    }

    /**
     * Brand, name and descriptive title, without repeating the name when
     * the title already contains it.
     */
    private function pageTitle(Product $product): string
    {
        $name = (string) $product->translateAttribute('name');
        $title = (string) $product->translateAttribute('title');

        $parts = [
            $product->brand?->name,
            $title !== '' && str_contains(mb_strtolower($title), mb_strtolower($name)) ? $title : trim($name.' '.$title),
        ];

        return implode(' ', array_filter($parts));
    }

    private function product(): Product
    {
        return $this->product ??= Product::query()
            ->with([
                'brand',
                'media',
                'variants.values.option',
                'variants.prices',
            ])
            ->findOrFail($this->productId);
    }

    private function selection(): VariantSelection
    {
        return VariantSelection::forProduct($this->product(), [
            'colour' => $this->colour === '' ? null : $this->colour,
            ...$this->choices,
        ]);
    }

    /**
     * Copy the selection's state back to the component, so choices that
     * were dropped or made automatically are reflected in the page and URL.
     */
    private function sync(VariantSelection $selection): void
    {
        $selected = $selection->selected();

        $this->colour = $selected['colour'] ?? '';
        unset($selected['colour']);
        $this->choices = $selected;
    }

    /**
     * The selected colour's images, or every image when the product has no
     * colour option or no image for that colour.
     *
     * @return list<Media>
     */
    private function images(Product $product, VariantSelection $selection): array
    {
        $all = $product->getMedia(config('lunar.media.collection'));
        $colourName = $selection->selectedName('colour');

        if ($colourName !== null) {
            $matching = $all->filter(fn ($media): bool => $media->getCustomProperty('colour') === $colourName);

            if ($matching->isNotEmpty()) {
                return $matching->values()->all();
            }
        }

        return $all->values()->all();
    }

    /**
     * The English product sheet (a PDF in the product's `documents` collection, custom properties `type` = product_sheet and
     * `colour`) for the selected colour; else one that names no colour; else, with no colour selected, the first. Null when
     * there is none, so most products show no link (docs/data-contract.md, Media).
     *
     * @return ?array{url: string, colour: ?string}
     */
    private function productSheet(Product $product, VariantSelection $selection): ?array
    {
        $sheets = $product->getMedia(ProductMediaDefinitions::DOCUMENTS)
            ->filter(fn (Media $media): bool => $media->getCustomProperty('type') === 'product_sheet');

        $colour = $selection->selectedName('colour');

        $sheet = $sheets->first(fn (Media $media): bool => $colour !== null && $media->getCustomProperty('colour') === $colour)
            ?? $sheets->first(fn (Media $media): bool => blank($media->getCustomProperty('colour')))
            ?? ($colour === null ? $sheets->first() : null);

        return $sheet === null ? null : ['url' => $sheet->getUrl(), 'colour' => $sheet->getCustomProperty('colour') ?: null];
    }

    private function price(PriceDisplay $priceDisplay, VariantSelection $selection, ?ProductVariant $variant): ?DisplayPrice
    {
        if (! $priceDisplay->visible()) {
            return null;
        }

        if ($variant !== null) {
            return $priceDisplay->forVariant($variant);
        }

        $lowest = null;

        foreach ($selection->matchingVariants() as $candidate) {
            if ($candidate->prices->isEmpty()) {
                continue;
            }

            $price = $priceDisplay->forVariant($candidate);

            if ($lowest === null || $price->price->value < $lowest->price->value) {
                $lowest = $price;
            }
        }

        return $lowest;
    }

    private function pricesVary(VariantSelection $selection): bool
    {
        $values = [];

        foreach ($selection->matchingVariants() as $candidate) {
            foreach ($candidate->prices as $price) {
                $values[$price->price->value] = true;
            }
        }

        return count($values) > 1;
    }

    /**
     * The button says what is still needed, so a disabled button is never
     * unexplained: "Select a size", "Enter a quantity", "Out of stock" or
     * "Add to cart".
     */
    private function addLabel(VariantSelection $selection, ?ProductVariant $variant): string
    {
        if ($this->stockMessage($selection, $variant) === 'Out of stock') {
            return 'Out of stock';
        }

        if ($variant !== null) {
            return $this->quantityValue() === null ? 'Enter a quantity' : 'Add to cart';
        }

        $missing = $selection->missing()[0] ?? null;

        return $missing === null
            ? 'Unavailable'
            : Str::replaceFirst('Please select', 'Select', $selection->promptFor($missing));
    }

    /**
     * The quantity as a whole number of at least 1, or null when the field
     * is empty or holds anything else.
     */
    private function quantityValue(): ?int
    {
        $quantity = trim($this->quantity);

        if (! ctype_digit($quantity) || strlen($quantity) > 6) {
            return null;
        }

        return (int) $quantity >= 1 ? (int) $quantity : null;
    }

    /**
     * Shown under the field once something has been typed that cannot be
     * ordered. An untouched, empty field is not an error.
     */
    private function quantityError(?ProductVariant $variant): ?string
    {
        if (trim($this->quantity) === '') {
            return null;
        }

        $quantity = $this->quantityValue();

        if ($quantity === null) {
            return 'Enter a whole number, 1 or more.';
        }

        if ($variant !== null && $variant->stock > 0 && $quantity > $variant->stock) {
            return "Only {$variant->stock} available in this size.";
        }

        return null;
    }

    private function stockMessage(VariantSelection $selection, ?ProductVariant $variant): string
    {
        if ($variant !== null) {
            return match (true) {
                $variant->stock <= 0 => 'Out of stock',
                $variant->stock <= self::LOW_STOCK => "Only {$variant->stock} left",
                default => 'In stock',
            };
        }

        foreach ($selection->matchingVariants() as $candidate) {
            if ($candidate->stock > 0) {
                return 'In stock';
            }
        }

        return 'Out of stock';
    }

    /**
     * EU size by UK size, taken from the selected colour's variants first.
     *
     * @return array<string, string>
     */
    private function euSizes(Product $product, VariantSelection $selection): array
    {
        $colour = $selection->selectedName('colour');
        $sizes = [];

        foreach ($product->variants as $variant) {
            $size = $variant->values->firstWhere('option.handle', 'shoe_size')?->translate('name');
            $eu = $variant->attr('eu_size');

            if ($size === null || blank($eu)) {
                continue;
            }

            $isSelectedColour = $colour !== null
                && $variant->values->firstWhere('option.handle', 'colour')?->translate('name') === $colour;

            if ($isSelectedColour || ! isset($sizes[(string) $size])) {
                $sizes[(string) $size] = (string) $eu;
            }
        }

        return $sizes;
    }

    /**
     * @return list<array{label: string, values: list<array{text: string, tooltip: ?string}>}>
     */
    private function specification(Product $product): array
    {
        $rows = [];

        foreach (self::SPECIFICATION as $handle => $label) {
            $raw = $product->attr($handle);
            $items = array_values(array_filter(is_array($raw) ? $raw : [$raw], filled(...)));

            if ($items === []) {
                continue;
            }

            $rows[] = [
                'label' => $label,
                'values' => array_map(fn (mixed $item): array => [
                    'text' => $handle === 'country_of_origin' ? CountryName::of((string) $item) : (string) $item,
                    'tooltip' => null,
                ], $items),
            ];
        }

        return $rows;
    }

    /**
     * Only YouTube embed URLs are put in an iframe.
     */
    private function videoUrl(Product $product): ?string
    {
        $url = (string) $product->attr('video_url');

        return preg_match('#^https://www\.youtube(-nocookie)?\.com/embed/[\w-]+$#', $url) === 1 ? $url : null;
    }

    /**
     * The related products chosen on mount (RelatedProductIds), loaded, published only, in the chosen order.
     *
     * @return list<Product>
     */
    private function related(): array
    {
        if ($this->relatedIds === []) {
            return [];
        }

        $products = Product::query()
            ->whereIn('id', $this->relatedIds)
            ->where('status', 'published')
            ->with(['brand', 'defaultUrl', 'thumbnail', 'variants.prices', 'variants.values.option'])
            ->get()
            ->keyBy('id');

        return collect($this->relatedIds)
            ->map(fn (int $id): ?Product => $products->get($id))
            ->filter()
            ->values()
            ->all();
    }
}
