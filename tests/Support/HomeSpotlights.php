<?php

declare(strict_types=1);

namespace Tests\Support;

use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Product;

/**
 * Three published products and the Product Spotlight campaigns for them, for tests of the home page. The fixture
 * catalogue has no CADOR, TOPSKATE or MAXIMUS product, so a test makes its own and points `homepage.spotlights` at
 * them. The images are the real campaign images in public/images/home.
 */
final class HomeSpotlights
{
    /**
     * @return list<Product> One product for each campaign, in the order of the config.
     */
    public static function seed(string $status = 'published'): array
    {
        $names = ['Spotlight Boot One', 'Spotlight Boot Two', 'Spotlight Boot Three'];
        $files = [
            ['cador-colours-that-work', true, 1200, 1563],
            ['topskate-s3s-safety-sneaker', false, 1200, 1518],
            ['maximus-helmet', false, 1200, 1508],
        ];

        $products = [];
        $spotlights = [];

        foreach ($names as $index => $name) {
            $product = CatalogueFactory::product($name, [
                ['colour' => 'Black', 'size' => '8', 'stock' => 5],
                ['colour' => 'Black', 'size' => '9', 'stock' => 3],
                ['colour' => 'Brown', 'size' => '8', 'stock' => 0],
                ['colour' => 'Brown', 'size' => '9', 'stock' => 0],
            ], $status, 5000, 'standard');

            // The long description is plain text, one paragraph to a line, as the catalogue holds it.
            $product->update(['attribute_data' => $product->attribute_data->merge([
                'description' => new TranslatedText(['en' => new Text('First paragraph of '.$name.".\nSecond paragraph of ".$name.'.')]),
                'short_description' => new TranslatedText(['en' => new Text('Short description of '.$name.'.')]),
            ])]);
            [$file, $webp, $width, $height] = $files[$index];

            $products[] = $product;
            $spotlights[] = [
                'slug' => 'test-brand-'.str($name)->slug(),
                'file' => $file,
                'webp' => $webp,
                'alt' => 'Campaign image '.($index + 1),
                'width' => $width,
                'height' => $height,
            ];
        }

        config(['homepage.spotlights' => $spotlights]);

        return $products;
    }
}
