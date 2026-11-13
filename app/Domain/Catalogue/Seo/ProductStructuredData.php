<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Seo;

use App\Domain\Catalogue\Variants\VariantSelection;
use App\Domain\Pricing\PriceDisplay;
use App\Domain\Pricing\VatDisplayMode;
use Lunar\Models\Product;

/**
 * schema.org Product markup with one Offer per variant.
 *
 * Offer prices are always inc VAT at the individual-use rate, whatever the
 * visitor's display mode, because that is what a consumer pays.
 */
final class ProductStructuredData
{
    public function __construct(private readonly PriceDisplay $priceDisplay) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Product $product, string $canonicalUrl): array
    {
        $product->loadMissing(['brand', 'variants.values.option', 'variants.prices']);

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => trim(($product->brand?->name ?? '').' '.$product->translateAttribute('name')),
            'url' => $canonicalUrl,
        ];

        $description = $product->translateAttribute('short_description') ?: $product->translateAttribute('description');

        if (filled($description)) {
            $data['description'] = (string) $description;
        }

        if ($product->brand !== null) {
            $data['brand'] = ['@type' => 'Brand', 'name' => $product->brand->name];
        }

        $images = $product->getMedia(config('lunar.media.collection'))
            ->map(fn ($media): string => $media->getUrl('large'))
            ->values()
            ->all();

        if ($images !== []) {
            $data['image'] = $images;
        }

        $offers = [];

        foreach ($product->variants as $variant) {
            if ($variant->prices->isEmpty()) {
                continue;
            }

            $pence = $this->priceDisplay->forVariant($variant, VatDisplayMode::IncVat)->price->value;
            $colour = $variant->values->firstWhere('option.handle', 'colour');

            $offer = [
                '@type' => 'Offer',
                'sku' => $variant->sku,
                'price' => sprintf('%d.%02d', intdiv($pence, 100), $pence % 100),
                'priceCurrency' => 'GBP',
                'availability' => $variant->stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'itemCondition' => 'https://schema.org/NewCondition',
                'url' => $colour === null
                    ? $canonicalUrl
                    : $canonicalUrl.'?colour='.VariantSelection::key('colour', (string) $colour->translate('name')),
            ];

            if (preg_match('/^\d{13}$/', (string) $variant->ean) === 1) {
                $offer['gtin13'] = $variant->ean;
            }

            $offers[] = $offer;
        }

        if ($offers !== []) {
            $data['offers'] = $offers;
        }

        return $data;
    }

    public function toJson(Product $product, string $canonicalUrl): string
    {
        return json_encode(
            $this->build($product, $canonicalUrl),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
        );
    }
}
