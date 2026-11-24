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
     * The video section under the industries slider. The heading and intro frame it; one of the videos below is
     * picked at random on every page load. `src` is the full file URL (a #t=0.5 fragment makes the browser show a
     * frame from half a second in instead of a black box). Optional: `caption` (a line under the player, the label
     * is used when there is none), `link` (a `route` name and a `label`, shown beside the caption), `poster`
     * (an image shown before it plays), `loop` and `preload`. The files are hosted by frontline-footwear.com, not by
     * this site, so a video that is moved or removed there stops playing here: check them now and then.
     *
     * Only footwear and technology clips rotate for now. The others are kept in `videos_held` until their
     * categories launch (workwear, gloves, head protection): move one up to `videos` then. The captions are worded
     * from each clip's own title: the client should confirm them.
     */
    'video_section' => [
        'title' => 'See how it works',
        'intro' => 'Short films on the features that matter on site, from toe caps and midsoles to slip resistance.',
    ],

    'videos' => [
        ['label' => 'Professional applications', 'src' => 'https://frontline-footwear.com/shop/datasheets/PROFESSIONAL%20APPLICATIONS_360p.mp4#t=0.5', 'caption' => 'Safety footwear for professional workplaces.', 'link' => ['route' => 'shop', 'label' => 'Shop safety footwear']],
        ['label' => 'Industrial applications', 'src' => 'https://frontline-footwear.com/shop/datasheets/INDUSTRIAL%20APPLICATIONS_360p.mp4#t=0.5', 'caption' => 'Safety footwear for industrial workplaces.', 'link' => ['route' => 'shop', 'label' => 'Shop safety footwear']],
        ['label' => 'Tiger Grip technology', 'src' => 'https://frontline-footwear.com/shop/datasheets/TIGER%20GRIP%20TECHNOLOGY_360p.mp4#t=0.5', 'caption' => 'How the Tiger Grip technology works.', 'link' => ['route' => 'technical-features', 'label' => 'See the technical features']],
        ['label' => 'Midsoles', 'src' => 'https://frontline-footwear.com/shop/datasheets/MIDSOLES_360p.mp4#t=0.5', 'caption' => 'What the midsole does in safety footwear.', 'link' => ['route' => 'technical-features', 'label' => 'See the technical features']],
        ['label' => 'Toecaps', 'src' => 'https://frontline-footwear.com/shop/datasheets/TOECAPS_360p.mp4#t=0.5', 'caption' => 'The toe caps used in safety footwear.', 'link' => ['route' => 'technical-features', 'label' => 'See the technical features']],
        ['label' => 'Slip resistance', 'src' => 'https://frontline-footwear.com/shop/datasheets/SLIP%20RESISTANCE_360p.mp4#t=0.5', 'caption' => 'How slip resistance is built into safety footwear.', 'link' => ['route' => 'technical-features', 'label' => 'See the technical features']],
        ['label' => 'Tiger Grip', 'src' => 'https://frontline-footwear.com/shop/datasheets/TIGERGRIP_540p%20.mp4#t=0.5', 'preload' => 'metadata', 'caption' => 'Tiger Grip in use.', 'link' => ['route' => 'technical-features', 'label' => 'See the technical features']],
        ['label' => 'Taman', 'src' => 'https://frontline-footwear.com/shop/datasheets/TAMAN.mp4#t=0.5', 'preload' => 'metadata', 'link' => ['route' => 'shop', 'label' => 'Shop safety footwear']],
        ['label' => 'CADOR colours that work', 'src' => 'https://frontline-footwear.com/shop/datasheets/CADOR%20Colours%20that%20work.mp4', 'poster' => 'https://frontline-footwear.com/v1.jpg', 'loop' => true, 'preload' => 'metadata', 'caption' => 'The CADOR range, in a choice of colours.', 'link' => ['route' => 'shop', 'label' => 'Shop safety footwear']],
    ],

    // Clips for products that are not on the site yet. Not shown: move one to `videos` when its category launches.
    'videos_held' => [
        ['label' => 'Eye protection', 'src' => 'https://frontline-footwear.com/shop/datasheets/EYE%20PROTECTION_360p.mp4#t=0.5'],
        ['label' => 'Head protection', 'src' => 'https://frontline-footwear.com/shop/datasheets/HEAD%20PROTECTION_360p.mp4#t=0.5'],
        ['label' => 'High visibility clothing', 'src' => 'https://frontline-footwear.com/shop/datasheets/HIGH%20VISIBILITY%20CLOTHING_360p.mp4#t=0.5'],
        ['label' => 'Work jackets', 'src' => 'https://frontline-footwear.com/shop/datasheets/WORK%20JACKETS_360p%20(1).mp4#t=0.5'],
    ],

    /*
     * The Product Spotlight, below the featured products: one campaign picked at random on every page load,
     * shown as its image beside the brand, the product's name, the product's own long description (read from the
     * catalogue, not written here), the price for signed-in customers and a button to the product page. `slug` is
     * the product's URL slug. A campaign whose product is not in the catalogue, or is not published, is left out,
     * and with none left the section is not shown. Images are in public/images/home.
     */
    'spotlights' => [
        [
            'slug' => 'safety-jogger-cador',
            'file' => 'cador-colours-that-work',
            'webp' => true,
            'alt' => 'CADOR S1 P safety trainer in yellow, green, blue, pink, red and grey, with its AirBlaze ventilation, SRC slip resistance, ESD, SJ Foam footbed and steel toe cap and midsole.',
            'width' => 1200,
            'height' => 1563,
        ],
        [
            'slug' => 'safety-jogger-topskate-s3s-low',
            'file' => 'topskate-s3s-safety-sneaker',
            'webp' => false,
            'alt' => 'TOPSKATE S3S, the safety sneaker you can lean on, in light grey, dark grey and blue.',
            'width' => 1200,
            'height' => 1518,
        ],
        [
            'slug' => 'safety-jogger-maximus',
            'file' => 'maximus-helmet',
            'webp' => false,
            'alt' => 'MAXIMUS safety helmet with ERT Energy Reduction Technology, in yellow, white and blue.',
            'width' => 1200,
            'height' => 1508,
        ],
    ],

    /*
     * The "Shop by brand" logo strip, straight under the trust strip. The brands are the storefront's own
     * (BrandDirectory, the same as /brands): those with published products, most products first, up to `limit`.
     * Each logo links to that brand's products. With no brand to show the section is left out.
     */
    'brands' => [
        'title' => 'Shop by brand',
        'limit' => 12,
    ],

    /*
     * The buying guides row, between the video and the testimonials: one card per item, each a link to a page
     * of the site by its route name. `title` and `text` frame the page, `link` is the link's words, `icon` is
     * the picture beside the title (`ruler` or `shield`; none when left out or unknown), `image` is a photo
     * behind the card under a dark overlay (`file` in public/images/home, with its `width` and `height`; a plain
     * white card when left out). The photos are copies of ones the site already uses (hero-bg-1 and hero-bg-7). An item
     * whose route does not exist is left out, and with none left the section is not shown.
     */
    'guides' => [
        'title' => 'Buying guides',
        'intro' => 'Choose the right size and the right protection, first time.',
        'items' => [
            [
                'route' => 'size-guide',
                'icon' => 'ruler',
                'image' => ['file' => 'guide-size-guide.jpg', 'width' => 1000, 'height' => 459],
                'title' => 'Size guide',
                'text' => 'UK to EU size conversions and measuring advice for footwear, workwear and gloves.',
                'link' => 'Open the size guide',
            ],
            [
                'route' => 'technical-features',
                'icon' => 'shield',
                'image' => ['file' => 'guide-technical-features.jpg', 'width' => 1000, 'height' => 459],
                'title' => 'Technical features',
                'text' => 'Safety icons, toe caps, slip resistance, waterproofing, ESD and more, explained.',
                'link' => 'See the technical features',
            ],
        ],
    ],

    /*
     * Customer testimonials, between the featured products and the service strip. Each has the words (`quote`), the
     * `role` of the person and the `sector` they work in; nobody is named. With no items the section is not shown.
     * The copy is the client's own. It must stay genuine and attributable: confirm each one with the person it is
     * from, and with the client, before launch (docs/launch-checklist.md). Some of what they say is about products
     * and brands the site does not sell yet (eyewear, height safety, Stanley, Helly Hansen), so read them against
     * the range on the day the site goes live. No dashes in the copy.
     */
    'testimonials' => [
        'title' => 'What our customers say',
        'items' => [
            [
                'quote' => 'Before switching to Frontline, we were juggling three different vendors just to cover footwear, eyewear, and height safety. Consolidating our PPE spend into a single trade account cut our admin time in half, and having instant access to brands like Safety Jogger, Stanley, and Helly Hansen in one place makes site restocks effortless.',
                'role' => 'Procurement Manager',
                'sector' => 'Logistics & Warehousing',
            ],
            [
                'quote' => "When you're kitting out 150+ site operatives, compliance isn't negotiable, but getting staff to actually wear their gear is half the battle. Frontline's range of EN ISO 20345 safety footwear and ergonomic helmets hits the sweet spot between strict site standards and genuine all-day comfort. Return rates and comfort complaints dropped immediately.",
                'role' => 'Health & Safety Lead',
                'sector' => 'Commercial Construction',
            ],
            [
                'quote' => 'Reliable stock, sharp trade pricing, and zero chasing for tracking numbers. Exactly what a PPE supplier should be.',
                'role' => 'Site Manager',
                'sector' => 'Civil Engineering',
            ],
        ],
    ],

    /*
     * The PPE Health Check band, above "Shop by brand". The words are the client's, from the Health Check page
     * (resources/views/pages/ppe-health-check.blade.php); the survey address is config('store.ppe_health_check_url').
     * The scorecard rows are an illustration, shown as an example: made-up scores, not anyone's result.
     */
    'health_check' => [
        'eyebrow' => 'Free PPE scorecard',
        'title' => 'Is Your PPE Costing You More Than It Should?',
        'text' => 'Discover hidden gaps in PPE compliance, workforce protection and supply reliability. Take our free 3-minute assessment and see where your business could reduce risk, save time and cut unnecessary costs.',
        'note' => 'Free. 12 questions. About 3 minutes.',
        'example' => [
            ['label' => 'Compliance and Standards', 'score' => 72],
            ['label' => 'Selection and Fit', 'score' => 58],
            ['label' => 'Purchasing and Spend', 'score' => 44],
        ],
    ],

];
