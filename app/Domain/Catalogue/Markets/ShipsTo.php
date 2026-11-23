<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Markets;

use Lunar\Models\Product;

/**
 * Where a product can be delivered. The data contract fixes every variant SKU as `{VENDOR_CODE}-{vendor_sku}` and a
 * product has one vendor, so the vendor code at the start of its SKUs says which vendor it comes from, without reading
 * the vendor tables (which the storefront must not do). `config/store.php` (`markets`) maps a vendor code to its
 * markets: Safety Jogger (SJ) `uk` and `eu`, Footsure (FS) `uk` only.
 *
 * The safe default is UK only: a vendor that is not in the map, a SKU with no vendor code, and a product with no
 * variants all give `uk`. Markets are lower-case words, in the order the config lists them.
 *
 * If one product ever needs to differ from its vendor, replace this with a product attribute written by the import;
 * what reads the markets (the search index field `ships_to`) stays the same.
 */
final class ShipsTo
{
    public const string UK = 'uk';

    public const string EU = 'eu';

    /**
     * The markets of a product, from its variants' SKUs.
     *
     * @return list<string>
     */
    public function forProduct(Product $product): array
    {
        $product->loadMissing('variants');

        return $this->forSkus($product->variants->pluck('sku')->all());
    }

    /**
     * The markets every one of these SKUs can go to. Mixed vendors (they should not be mixed) share only the markets
     * they have in common.
     *
     * @param  iterable<int, string|null>  $skus
     * @return list<string>
     */
    public function forSkus(iterable $skus): array
    {
        $markets = null;

        foreach ($skus as $sku) {
            $mine = $this->forSku((string) $sku);
            $markets = $markets === null ? $mine : array_values(array_intersect($markets, $mine));
        }

        return $markets ?? $this->default();
    }

    /** @return list<string> */
    public function forSku(string $sku): array
    {
        $code = strtoupper(trim(strstr(trim($sku), '-', true) ?: ''));

        if ($code === '') {
            return $this->default();
        }

        foreach ((array) config('store.markets.vendors') as $vendor => $markets) {
            if (strtoupper((string) $vendor) === $code) {
                return $this->clean((array) $markets);
            }
        }

        return $this->default();
    }

    /** Whether the product can be delivered to the EU. */
    public function deliversToEu(Product $product): bool
    {
        return in_array(self::EU, $this->forProduct($product), true);
    }

    /** @return list<string> */
    private function default(): array
    {
        return $this->clean((array) config('store.markets.default'));
    }

    /**
     * @param  array<int|string, mixed>  $markets
     * @return list<string>
     */
    private function clean(array $markets): array
    {
        return array_values(array_unique(array_filter(array_map(fn (mixed $market): string => strtolower(trim((string) $market)), $markets), fn (string $market): bool => $market !== '')));
    }
}
