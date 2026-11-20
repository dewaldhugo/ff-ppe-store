<?php

declare(strict_types=1);

return [

    /*
     * The hero heading. One is picked at random on every page load. Plain text only (it is
     * escaped when shown), so write an apostrophe as a plain ' and not an HTML entity.
     */
    'hero_headings' => [
        'Designed for the People Who Keep Everything Moving.',
        'Trusted protection for every shift.',
        'Certified gear built for the toughest sites.',
    ],

    /*
     * The "Shop by standard" chips on the home page. Each links to the
     * safety-footwear listing filtered by one facet value. `facet` is a
     * ListingQuery facet (safety_class or features). The label is the
     * feature's own label unless `label` is set here.
     *
     * Before launch, check on the real catalogue that every chip returns
     * products (see the Launch checklist in CLAUDE.md).
     */
    'standards' => [
        ['facet' => 'safety_class', 'value' => 'S1P'],
        ['facet' => 'safety_class', 'value' => 'S3'],
        ['facet' => 'features', 'value' => 'ESD', 'label' => 'ESD'],
        ['facet' => 'features', 'value' => 'METAL_FREE'],
        ['facet' => 'features', 'value' => 'WR', 'label' => 'Waterproof'],
    ],

    /*
     * The trust strip under the industries slider. Each slot is one short claim. An empty
     * slot is not rendered. The fourth slot is for a delivery claim that the
     * client has not confirmed yet: leave it empty until they do (see the
     * Launch checklist in CLAUDE.md).
     */
    'trust' => [
        'Prices exclusive of VAT',
        'Safety footwear to EN ISO 20345',
        'Wide range of sizes',
        '',
    ],

    /*
     * The video above the featured products. One is picked at random on every page load. `src` is
     * the full file URL (a #t=0.5 fragment makes the browser show a frame from half a second in
     * instead of a black box). Optional: `poster` (an image shown before it plays), `loop` and
     * `preload`. The files are hosted by frontline-footwear.com, not by this site, so a video that
     * is moved or removed there stops playing here: check them now and then.
     */
    'videos' => [
        ['label' => 'Professional applications', 'src' => 'https://frontline-footwear.com/shop/datasheets/PROFESSIONAL%20APPLICATIONS_360p.mp4#t=0.5'],
        ['label' => 'Industrial applications', 'src' => 'https://frontline-footwear.com/shop/datasheets/INDUSTRIAL%20APPLICATIONS_360p.mp4#t=0.5'],
        ['label' => 'Eye protection', 'src' => 'https://frontline-footwear.com/shop/datasheets/EYE%20PROTECTION_360p.mp4#t=0.5'],
        ['label' => 'Head protection', 'src' => 'https://frontline-footwear.com/shop/datasheets/HEAD%20PROTECTION_360p.mp4#t=0.5'],
        ['label' => 'High visibility clothing', 'src' => 'https://frontline-footwear.com/shop/datasheets/HIGH%20VISIBILITY%20CLOTHING_360p.mp4#t=0.5'],
        ['label' => 'Tiger Grip technology', 'src' => 'https://frontline-footwear.com/shop/datasheets/TIGER%20GRIP%20TECHNOLOGY_360p.mp4#t=0.5'],
        ['label' => 'Midsoles', 'src' => 'https://frontline-footwear.com/shop/datasheets/MIDSOLES_360p.mp4#t=0.5'],
        ['label' => 'Toecaps', 'src' => 'https://frontline-footwear.com/shop/datasheets/TOECAPS_360p.mp4#t=0.5'],
        ['label' => 'Slip resistance', 'src' => 'https://frontline-footwear.com/shop/datasheets/SLIP%20RESISTANCE_360p.mp4#t=0.5'],
        ['label' => 'Work jackets', 'src' => 'https://frontline-footwear.com/shop/datasheets/WORK%20JACKETS_360p%20(1).mp4#t=0.5'],
        ['label' => 'Tiger Grip', 'src' => 'https://frontline-footwear.com/shop/datasheets/TIGERGRIP_540p%20.mp4#t=0.5', 'preload' => 'metadata'],
        ['label' => 'Taman', 'src' => 'https://frontline-footwear.com/shop/datasheets/TAMAN.mp4#t=0.5', 'preload' => 'metadata'],
        ['label' => 'CADOR colours that work', 'src' => 'https://frontline-footwear.com/shop/datasheets/CADOR%20Colours%20that%20work.mp4', 'poster' => 'https://frontline-footwear.com/v1.jpg', 'loop' => true, 'preload' => 'metadata'],
    ],

];
