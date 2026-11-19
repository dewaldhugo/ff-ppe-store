<?php

declare(strict_types=1);

namespace App\Domain\Cart;

use Lunar\Models\Cart;
use Lunar\Models\ProductVariant;

/**
 * A cart's lines as plain arrays for display, shared by the cart page and the mini cart.
 * Prices are Lunar's, already calculated on the cart; nothing is worked out here.
 */
final class CartLineViews
{
    /**
     * `total` is the line ex VAT; `vat` and `incVat` are Lunar's tax on it, and `zeroRated` is true
     * when the line carries no VAT at all (the `zero-childrens` class).
     *
     * @return list<array{id: int, sku: string, name: string, brand: ?string, options: list<array{label: string, value: string}>, url: ?string, image: ?string, quantity: int, max: ?int, unit: string, total: string, vatRate: string, vat: string, incVat: string, zeroRated: bool, problem: ?string}>
     */
    public function for(Cart $cart): array
    {
        $cart->loadMissing([
            'lines.purchasable.values.option',
            'lines.purchasable.product.brand',
            'lines.purchasable.product.media',
            'lines.purchasable.product.defaultUrl',
        ]);

        $lines = [];

        foreach ($cart->lines as $line) {
            /** @var ProductVariant $variant */
            $variant = $line->purchasable;
            $product = $variant->product;

            $options = [];
            $colour = null;

            foreach ($variant->values as $value) {
                $handle = $value->option->handle;
                $name = (string) $value->translate('name');

                if ($handle === 'colour') {
                    $colour = $name;
                }

                $options[] = ['label' => $handle === 'colour' ? 'Colour' : 'Size', 'value' => $name];
            }

            $media = $product->getMedia(config('lunar.media.collection'));
            $image = $media->first(fn ($item): bool => $colour !== null && $item->getCustomProperty('colour') === $colour) ?? $media->first();

            $rate = (float) ($line->taxBreakdown?->amounts->sum('percentage') ?? 0);

            $lines[] = [
                'id' => $line->id,
                'sku' => (string) $variant->sku,
                'name' => (string) $product->translateAttribute('name'),
                'brand' => $product->brand?->name,
                'options' => $options,
                'url' => $product->defaultUrl === null ? null : route('product', $product->defaultUrl->slug),
                'image' => $image?->getUrl('thumb'),
                'quantity' => $line->quantity,
                'max' => $variant->getTotalInventory() > 0 ? $variant->getTotalInventory() : null,
                'unit' => (string) $line->unitPrice->formatted(trimTrailingZeros: false),
                'total' => (string) $line->subTotal->formatted(trimTrailingZeros: false),
                'vatRate' => rtrim(rtrim(number_format($rate, 2), '0'), '.'),
                'vat' => (string) $line->taxAmount->formatted(trimTrailingZeros: false),
                'incVat' => (string) $line->total->formatted(trimTrailingZeros: false),
                'zeroRated' => $rate === 0.0,
                'problem' => $this->problem($variant, $line->quantity),
            ];
        }

        return $lines;
    }

    /**
     * Whether every line can be bought as it stands.
     *
     * @param  list<array{problem: ?string}>  $lines
     */
    public function canCheckout(array $lines): bool
    {
        return $lines !== [] && collect($lines)->every(fn (array $line): bool => $line['problem'] === null);
    }

    /**
     * Why a line cannot be bought as it stands (stock fell or the product was withdrawn
     * since it was added), or null when it can.
     */
    private function problem(ProductVariant $variant, int $quantity): ?string
    {
        if (! $variant->isPurchasable()) {
            return 'This item is no longer available. Remove it to continue.';
        }

        if ($variant->canBeFulfilledAtQuantity($quantity)) {
            return null;
        }

        $stock = $variant->getTotalInventory();

        return $stock > 0
            ? "Only {$stock} in stock. Reduce the quantity to continue."
            : 'This item is out of stock. Remove it to continue.';
    }
}
