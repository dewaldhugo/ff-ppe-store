<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Seo;

use App\Domain\Catalogue\Variants\VariantSelection;
use App\Domain\Pricing\PriceDisplay;
use App\Support\PlainText;
use Lunar\Models\Product;

/**
 * schema.org Product markup with one Offer per variant.
 *
 * Every offer states its price ex VAT, explicitly: a priceSpecification with
 * valueAddedTaxIncluded false. The store sells to business buyers and shows
 * ex VAT prices everywhere, so the markup says the same.
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

        $description = PlainText::from($product->translateAttribute('short_description')) ?: PlainText::from($product->translateAttribute('description'));

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

        // Guests do not see prices, so the markup must not state them either.
        foreach ($this->priceDisplay->visible() ? $product->variants : [] as $variant) {
            if ($variant->prices->isEmpty()) {
                continue;
            }

            $pence = $this->priceDisplay->forVariant($variant)->price->value;
            $colour = $variant->values->firstWhere('option.handle', 'colour');

            $offer = [
                '@type' => 'Offer',
                'sku' => $variant->sku,
                'priceSpecification' => [
                    '@type' => 'UnitPriceSpecification',
                    'price' => sprintf('%d.%02d', intdiv($pence, 100), $pence % 100),
                    'priceCurrency' => 'GBP',
                    'valueAddedTaxIncluded' => false,
                ],
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
