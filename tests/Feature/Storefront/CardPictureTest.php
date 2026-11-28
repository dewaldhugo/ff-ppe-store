<?php

declare(strict_types=1);

// The margins round a product's picture are the cards' (.card-picture in resources/css/app.css), not the stored file's: the main
// image is cropped to its subject (App\Domain\VendorSync\Images\SubjectAligner), the card pads it and stands it on the bottom.

it('pads the card picture 15% above and below and 4% at the sides, fits it inside and stands it on the bottom', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    preg_match('/\.card-picture\s*\{([^}]*)\}/', $css, $rule);

    expect($rule)->not->toBeEmpty();

    $declarations = preg_replace('/\s+/', ' ', $rule[1]);

    expect($declarations)->toContain('object-fit: contain;')
        ->toContain('object-position: bottom;')
        ->toContain('padding: 15% 4%;')
        ->toContain('width: 100%;')
        ->toContain('height: 100%;');
});

it('uses the card picture on the listing cards and the home page picks', function (): void {
    foreach (['components/storefront/product-card.blade.php', 'components/home/product-picks.blade.php'] as $view) {
        $html = (string) file_get_contents(resource_path('views/'.$view));

        expect($html)->toContain('class="card-picture"')
            ->and($html)->not->toContain('class="h-full w-full object-contain"');
    }
});
