<?php

declare(strict_types=1);

namespace App\Livewire\Favourites;

use App\Domain\Cart\CartRejected;
use App\Domain\Cart\CustomerCart;
use App\Domain\Catalogue\Variants\VariantSelection;
use App\Domain\Favourites\Favourites;
use App\Domain\Pricing\DisplayPrice;
use App\Domain\Pricing\PriceDisplay;
use App\Domain\Pricing\PriceVisibility;
use App\Support\CartCount;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Component;
use Lunar\Models\Product;

/**
 * The quick order form (/favourites/quick-order), for signed-in customers (route middleware
 * `auth:web`): every favourite product in a row, with its colour and size to pick, a quantity and
 * an "Add to order" button that puts that variant in the cart.
 *
 * Nothing new about variants or the cart lives here: the choices follow the product page's rules
 * (VariantSelection: a size that does not exist in the chosen colour is dropped, out of stock
 * values cannot be picked) and every add goes through CustomerCart, which checks stock again
 * against what is already in the cart. A row can only add one of the customer's own favourites.
 */
final class QuickOrder extends Component
{
    /**
     * What is chosen on each row: product id, then option handle, then the value's key.
     *
     * @var array<int, array<string, string>>
     */
    public array $choices = [];

    /**
     * The quantity typed on each row, by product id. Strings, so a cleared field never breaks the page.
     *
     * @var array<int, string>
     */
    public array $quantities = [];

    /**
     * What happened on a row, by product id: a refusal or "Added".
     *
     * @var array<int, array{type: string, text: string}>
     */
    public array $messages = [];

    /** @var Collection<int, Product>|null The favourites, read once per request. */
    private ?Collection $products = null;

    public function mount(): void
    {
        foreach ($this->favourites() as $product) {
            $this->choices[$product->id] = VariantSelection::forProduct($product)->selected();
            $this->quantities[$product->id] = '1';
        }
    }

    /** A choice was made on a row: keep only the choices that still make sense together. */
    public function updatedChoices(mixed $value, string $key): void
    {
        $productId = (int) Str::before($key, '.');
        $product = $this->favourite($productId);

        unset($this->messages[$productId]);

        if ($product === null) {
            unset($this->choices[$productId]);

            return;
        }

        // Replay the other choices first and apply the one just made last, so it wins: choosing a colour that
        // does not come in the chosen size keeps the colour and drops the size, and never the other way round.
        $handle = Str::after($key, '.');
        $others = $this->choices[$productId] ?? [];
        $changed = $others[$handle] ?? null;
        unset($others[$handle]);

        $this->choices[$productId] = VariantSelection::forProduct($product, $others)->select($handle, $changed)->selected();
    }

    public function updatedQuantities(mixed $value, string $key): void
    {
        unset($this->messages[(int) $key]);
    }

    public function add(int $productId, PriceVisibility $visibility, CustomerCart $cart): void
    {
        unset($this->messages[$productId]);

        // Prices and ordering are for signed-in customers only.
        if (! $visibility->visible()) {
            return;
        }

        $product = $this->favourite($productId);

        if ($product === null) {
            $this->messages[$productId] = ['type' => 'error', 'text' => 'That product is no longer in your favourites.'];

            return;
        }

        $selection = VariantSelection::forProduct($product, $this->choices[$productId] ?? []);

        if (! $selection->isComplete()) {
            $this->messages[$productId] = ['type' => 'error', 'text' => $selection->promptFor($selection->missing()[0]).'.'];

            return;
        }

        $variant = $selection->variant();

        if ($variant === null || $variant->stock <= 0) {
            $this->messages[$productId] = ['type' => 'error', 'text' => 'That choice is out of stock.'];

            return;
        }

        $quantity = $this->quantity($productId);

        if ($quantity === null) {
            $this->messages[$productId] = ['type' => 'error', 'text' => 'Enter a whole number, 1 or more.'];

            return;
        }

        if ($quantity > $variant->stock) {
            $this->messages[$productId] = ['type' => 'error', 'text' => "Only {$variant->stock} available in this size."];

            return;
        }

        try {
            $cart->add($variant, $quantity);
        } catch (CartRejected $rejected) {
            $this->messages[$productId] = ['type' => 'error', 'text' => $rejected->getMessage()];

            return;
        }

        $this->messages[$productId] = ['type' => 'success', 'text' => "Added {$quantity} to your order."];

        // The header badge listens for this.
        $this->dispatch('cart-updated', count: CartCount::get());
    }

    public function render(PriceDisplay $priceDisplay): View
    {
        $rows = [];

        foreach ($this->favourites() as $product) {
            $selection = VariantSelection::forProduct($product, $this->choices[$product->id] ?? []);
            $variant = $selection->variant();

            $price = $variant !== null && $variant->prices->isNotEmpty()
                ? $priceDisplay->forVariant($variant)
                : $priceDisplay->fromPrice($product);

            $rows[] = [
                'id' => $product->id,
                'name' => (string) $product->translateAttribute('name'),
                'title' => (string) $product->translateAttribute('title'),
                'brand' => $product->brand?->name,
                'url' => route('product', $product->defaultUrl?->slug ?? $product->id),
                'image' => $product->thumbnail?->getUrl('thumb'),
                'options' => $selection->options(),
                'eu' => $this->euSizes($product, $selection),
                'price' => $price,
                'exact' => $variant !== null,
                'stock' => $variant === null ? null : (int) $variant->stock,
            ];
        }

        return view('livewire.favourites.quick-order', [
            'rows' => $rows,
            'suffix' => DisplayPrice::SUFFIX,
            'cartCount' => CartCount::get(),
        ])->layout('components.layouts.storefront', [
            'title' => 'Quick order',
            'noindex' => true,
        ]);
    }

    /**
     * The customer's published favourites, newest first, with the variants the form needs.
     *
     * @return Collection<int, Product>
     */
    private function favourites(): Collection
    {
        return $this->products ??= app(Favourites::class)->products();
    }

    /** One of the customer's own favourites, or null: a row can never add anyone else's product. */
    private function favourite(int $productId): ?Product
    {
        return $this->favourites()->firstWhere('id', $productId);
    }

    /** The quantity typed on the row as a whole number of at least 1, or null. */
    private function quantity(int $productId): ?int
    {
        $quantity = trim($this->quantities[$productId] ?? '');

        if (! ctype_digit($quantity) || strlen($quantity) > 6) {
            return null;
        }

        return (int) $quantity >= 1 ? (int) $quantity : null;
    }

    /**
     * EU size by UK size, taken from the chosen colour's variants first.
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

            $isChosenColour = $colour !== null
                && $variant->values->firstWhere('option.handle', 'colour')?->translate('name') === $colour;

            if ($isChosenColour || ! isset($sizes[(string) $size])) {
                $sizes[(string) $size] = (string) $eu;
            }
        }

        return $sizes;
    }
}
