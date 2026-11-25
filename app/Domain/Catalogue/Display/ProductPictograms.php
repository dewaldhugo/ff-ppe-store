<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Display;

use Lunar\Models\Product;

/**
 * The pictograms under a product's safety standard (CE, Safety toecap, Puncture resistant...): the vendor's own icons, exactly the
 * ones it lists for the product, in its order, each with its name and not a link. They come from the `pictograms` attribute (data
 * contract, Product attributes): `name|Label` entries, written by the ingestion phase in
 * prompts/vendorsync-pictograms-product-sheet.md. The icon of each is the file public/images/pictograms/{name}.png.
 *
 * Nothing is worked out from other data: the vendor chooses the icons per product (an S3 boot shows Puncture resistant without a
 * P code, and a CI code does not always get a Cold insulated icon), so a guess would show the wrong ones. An entry whose file is
 * missing is left out rather than shown as a broken image, and a product with no entries shows no block.
 */
final class ProductPictograms
{
    public const string DIRECTORY = 'images/pictograms';

    /**
     * @return list<array{name: string, label: string, url: string}>
     */
    public function for(Product $product): array
    {
        $pictograms = [];

        foreach ((array) $product->attr('pictograms') as $entry) {
            [$name, $label] = array_pad(explode('|', trim((string) $entry), 2), 2, '');

            $name = strtolower(trim($name));
            $label = trim($label);

            if (preg_match('/^[a-z0-9_-]+$/', $name) !== 1 || $label === '' || ! is_file(public_path(self::DIRECTORY."/{$name}.png"))) {
                continue;
            }

            $pictograms[$name] ??= ['name' => $name, 'label' => $label, 'url' => asset(self::DIRECTORY."/{$name}.png")];
        }

        return array_values($pictograms);
    }
}
