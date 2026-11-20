<?php

declare(strict_types=1);

/*
 * Vendor ingestion (VendorSync). Owned by the ingestion module.
 *
 * Secrets come from the environment only and have no defaults here.
 */
return [

    // The schedule only registers when this is true, so a developer machine
    // never polls the production API on its own.
    'schedule_enabled' => (bool) env('VENDORSYNC_SCHEDULE_ENABLED', false),

    // Failed, aborted and stale runs are mailed here and logged as errors.
    'alert_email' => env('VENDORSYNC_ALERT_EMAIL'),

    // Local only: DatabaseSeeder imports tests/Fixtures/VendorSync through
    // the real pipeline instead of running the fixture seeder.
    'seed_from_fixtures' => (bool) env('VENDORSYNC_SEED_FROM_FIXTURES', false),

    // Listing price ex VAT = vendor cost x markup, rounded to the penny.
    // This is the default; a vendor overrides it with `vendors.{CODE}.markup`.
    // A decimal string from 1.0 to 5, never a float. Anything else stops the
    // run when the mapper is resolved.
    'markup' => env('VENDORSYNC_MARKUP', '1.5'),

    // Price-change guard. When a variant's new vendor COST differs from its
    // stored cost by more than this percentage in one run, cost, price and
    // compare price are all kept and the change is held for review (listed
    // in the run report and the alert). Exactly this percentage passes.
    // 0 switches the guard off. A markup change never triggers it.
    'price_change_guard_percent' => (int) env('VENDORSYNC_PRICE_CHANGE_GUARD_PERCENT', 40),

    // Abort a catalogue run that sees fewer than this share of the products
    // seen by the previous successful run.
    'safety_threshold_percent' => 70,

    // Consecutive successful full runs a product may be absent from before
    // it is drafted.
    'discontinue_after_missed_runs' => 2,

    'messages_cap' => 200,

    // Products sent to the search index at a time at the end of a catalogue
    // run. A chunk that fails is reported and the run goes on; after three
    // failed chunks in a row the rest are not attempted (docs/vendorsync.md).
    'reindex_chunk' => 100,

    // Queue image jobs after a real catalogue run. `--without-images` turns
    // this off for one run; the command then prints the images command to run later.
    'queue_images' => (bool) env('VENDORSYNC_QUEUE_IMAGES', true),

    // Seconds a run holds its vendor and type lock. It must exceed the longest
    // expected run with margin (at least two hours); a run releases it when it ends.
    'lock_seconds' => 4 * 3600,

    'reports_path' => storage_path('app/vendorsync/reports'),

    'vendors' => [
        'SJ' => [
            'name' => 'Safety Jogger',
            // Null uses the default `markup` above.
            'markup' => env('VENDORSYNC_SJ_MARKUP'),
            'stock_buffer' => 0,
            // The 15 minute stock feed is fresher than the catalogue.
            'stock_authority' => 'feed',
        ],
        'FS' => [
            'name' => 'Footsure',
            'markup' => env('VENDORSYNC_FS_MARKUP'),
            'stock_buffer' => 1,
            'stock_authority' => 'catalogue',
        ],
    ],

    // The feature codes of the data contract. Anything else is dropped and
    // reported, never invented.
    'features' => [
        'SRA', 'SRB', 'SRC', 'SR', 'ESD', 'CI', 'HI', 'HRO', 'WR', 'FO', 'M', 'AN', 'E', 'A', 'P', 'LG', 'SC', 'WRU', 'METAL_FREE',
    ],

    // EU to UK size charts by vendor code. Safety Jogger's published chart,
    // identical for men and women. Never guess a missing conversion.
    'size_charts' => [
        'SJ' => [
            '35' => '3', '36' => '3.5', '37' => '4', '38' => '5', '39' => '6', '40' => '6.5', '41' => '7',
            '42' => '8', '43' => '9', '44' => '10', '45' => '10.5', '46' => '11', '47' => '12', '48' => '13',
            '49' => '13.5', '50' => '14',
            '35/36' => '3/3.5', '37/38' => '4/5', '39/40' => '6/6.5', '41/42' => '7/8', '43/44' => '9/10',
            '45/46' => '10.5/11', '47/48' => '12/13', '49/50' => '13.5/14',
        ],
    ],

    'safety_jogger' => [
        'base_url' => env('VENDORSYNC_SJ_BASE_URL'),
        'username' => env('VENDORSYNC_SJ_USERNAME'),
        'password' => env('VENDORSYNC_SJ_PASSWORD'),
        // Used instead of the password when set.
        'token' => env('VENDORSYNC_SJ_TOKEN'),
        'validity_days' => 30,
        'language' => 'en',
        'timeout' => 30,
        'requests_per_second' => 2,
        'retries' => 3,

        // The vendor's answers are not stable: the same account has returned
        // EUR prices for different products on different runs, and a product
        // detail has answered "an unexpected response". A product whose
        // response is not usable is fetched again, at most `refetches` more
        // times (never more than 2). The first re-fetch waits the first delay,
        // the second the second one (seconds). The first fully GBP response is
        // used; a product that never gets one keeps its stored GBP price
        // (docs/vendorsync.md, Currency instability).
        'refetches' => 2,
        'refetch_delay_seconds' => (int) env('VENDORSYNC_SJ_REFETCH_DELAY', 2),
        'refetch_second_delay_seconds' => (int) env('VENDORSYNC_SJ_REFETCH_DELAY_SECOND', 10),

        // Catalogue scope: article type in this list. `shoes` additionally
        // needs an EN ISO 20345 or EN ISO 20347 norm (SafetyJoggerScope);
        // every other listed type is in scope on its own. Anything not
        // listed here is out of scope.
        'article_types' => ['shoes', 'workwear', 'gloves', 'head_protection', 'fall_protection', 'overshoes', 'accessories'],
        'excluded_brands' => [],

        // RRP includes VAT at this rate whatever the tax class (basis points).
        'rrp_vat_basis_points' => 2000,

        // zero-protective only for these models with an EN ISO 20345 norm.
        // `low_boot` stays standard-rated until the accountant confirms.
        'boot_models' => ['high_boot', 'high'],

        // model name => contract `cut`.
        'cut_map' => [
            'low' => 'Low',
            'clog' => 'Low',
            'sandal' => 'Low',
            'pump' => 'Low',
            'low_boot' => 'Mid',
            'high' => 'High',
            'high_boot' => 'High',
        ],

        // Benefit name => contract feature code.
        'benefit_map' => [
            'NOMETAL' => 'METAL_FREE',
        ],

        // Norm family => article type => model => collection handle.
        // "safety" means an EN ISO 20345 norm, "occupational" EN ISO 20347
        // only. "*" matches any model. Shoes only: SafetyJoggerScope.
        'categories' => [
            'safety' => [
                'shoes' => [
                    'low' => 'safety-shoes',
                    'sandal' => 'safety-shoes',
                    'clog' => 'safety-shoes',
                    'low_boot' => 'safety-boots',
                    'high' => 'safety-boots',
                    'high_boot' => 'safety-boots',
                ],
            ],
            'occupational' => [
                'shoes' => [
                    '*' => 'occupational-footwear',
                ],
            ],
        ],

        // workwear_product_category code => collection handle. An unknown
        // code drafts the product (unmapped_category), as an unmapped
        // Footsure Group Class does.
        'workwear_categories' => [
            'work_trousers' => 'trousers-shorts',
            'jacket' => 'jackets-fleeces',
            'sweater' => 'sweatshirts-hoodies',
            'tshirt_polo' => 'tshirts-polos-shirts',
            'socks' => 'socks',
            'thermal_base_layer' => 'base-layers',
            'accessories' => 'other-accessories',
        ],

        // headprotection_product_subcategory code => collection handle.
        'head_protection_categories' => [
            'hard_hats' => 'hard-hats-helmets',
            'spectacles' => 'eye-face-protection',
            'goggles' => 'eye-face-protection',
            'visors' => 'eye-face-protection',
            'earplugs' => 'hearing-protection',
            'earmuffs' => 'hearing-protection',
            'filtering' => 'respiratory-protection',
        ],

        // Article types with exactly one destination collection.
        'simple_categories' => [
            'gloves' => 'gloves',
            'fall_protection' => 'fall-protection',
            'overshoes' => 'overshoes',
        ],

        // Local seeding only: where FixtureSource reads its JSON from.
        'fixtures_path' => base_path('tests/Fixtures/VendorSync/SafetyJogger'),
    ],

    'footsure' => [
        'incoming_path' => env('VENDORSYNC_FS_INCOMING_PATH', storage_path('app/vendorsync/footsure/incoming')),
        'archive_path' => env('VENDORSYNC_FS_ARCHIVE_PATH', storage_path('app/vendorsync/footsure/archive')),
        'archive_days' => 30,
        'max_age_hours' => 36,

        // The export's File_Create_Date format. The real feed is month/day/year
        // (11/15/2026 06:05:26). Read as day/month/year it parses without error
        // as a date in the future, so the staleness rule could never fire.
        'file_date_format' => 'm/d/Y H:i:s',

        // A file created further ahead than this is unreadable, not fresh.
        'max_future_minutes' => 60,

        // Case-insensitive, matching any brand that starts with an entry.
        // Safety Jogger products come directly from the Safety Jogger API.
        'excluded_brands' => ['Safety Jogger'],

        // Vendor brand => customer-facing brand. Null means no brand.
        'brand_map' => [
            'Miscellaneous Other' => null,
        ],

        // Group Class => collection handle. An unmapped value drafts the
        // product and is reported. Every leaf here can hold either vendor's
        // products: a footwear leaf (CategoryTree::isFootwear()) gets the
        // footwear variant treatment regardless of which vendor supplies it,
        // and FootsureMapper's safety-rating check can still move a product
        // between the safety-footwear and non-safety-footwear leaves below
        // (a certified product filed under a non-safety Group Class, or an
        // uncertified one filed under a safety Group Class).
        'categories' => [
            'Boots Safety' => 'safety-boots',
            'Goodyear Welted Safety' => 'safety-boots',
            'Waterproof Safety Footwear' => 'safety-boots',
            'Ladies Safety Boots' => 'safety-boots',
            'Hiker Safety Footwear' => 'safety-boots',
            'Dealers Safety' => 'safety-boots',
            'Riggers Safety' => 'safety-boots',
            'Shoes- Safety' => 'safety-shoes',
            'Ladies Safety Shoes' => 'safety-shoes',
            'Trainers Safety' => 'safety-trainers',
            'Ladies Safety Trainers' => 'safety-trainers',
            'Safety Wellingtons' => 'safety-wellingtons',
            'Mens Occupational Footwear' => 'occupational-footwear',
            'Ladies Occupational Footwear' => 'occupational-footwear',
            'Unisex Occupational Footwear' => 'occupational-footwear',
            'Metal Free Safety Footwear' => 'metal-free-metatarsal',
            'Metatarsal Safety Footwear' => 'metal-free-metatarsal',
            // Slippers, plimsolls and the summer shoes and sandals Group Classes are deliberately not mapped:
            // those categories are retired (CategoryTree::RETIRED), so such a product is drafted as unmapped_category.
            'Plain Rubber Wellingtons' => 'wellingtons',
            'Plain PVC Wellingtons' => 'wellingtons',
            'Plain PU Wellingtons' => 'wellingtons',
            'Childrens Wellingtons' => 'wellingtons',
            'Patterned Wellingtons' => 'wellingtons',
            'Textile/Weather Wellingtons' => 'wellingtons',
            'ThermalWellingtons' => 'wellingtons',
            'Waders' => 'wellingtons',
            'Garden Shoes' => 'wellingtons',
            'Mens Hiking Boots' => 'hiking-outdoor',
            'Ladies Hiking Boots' => 'hiking-outdoor',
            'Mens Hiking Shoes' => 'hiking-outdoor',
            'Ladies Hiking Shoes' => 'hiking-outdoor',
            'Childrens Hiking Shoes' => 'hiking-outdoor',
            'Childrens Hiking Boots' => 'hiking-outdoor',
            'Mens Trail' => 'hiking-outdoor',
            'Ladies Trail' => 'hiking-outdoor',
            'Mens Boots' => 'boots',
            'Mens Classic Boots' => 'boots',
            'Mens Formal Boots' => 'boots',
            'Ladies Ankle Boots' => 'boots',
            'Ladies Long Boots' => 'boots',
            'Ladies Mid Boot' => 'boots',
            'Boys Boots' => 'boots',
            'Girls Boots' => 'boots',
            'Mens Classic Lace Shoes' => 'casual-footwear',
            'Mens Classic Slip On Shoes' => 'casual-footwear',
            'Mens Classic Touch Fastening Shoes' => 'casual-footwear',
            'Mens Formal Slip On Shoes' => 'casual-footwear',
            'Mens Formal Lace Ups' => 'casual-footwear',
            'Lace Ladies Shoes' => 'casual-footwear',
            'Slip On Ladies Shoes' => 'casual-footwear',
            'Court Ladies Shoes' => 'casual-footwear',
            'Touch Fastening Ladies Shoes' => 'casual-footwear',
            'Ladies Heels' => 'casual-footwear',
            'Riva Ballerinas' => 'casual-footwear',
            'Ballerinas' => 'casual-footwear',
            'Adult Clogs' => 'casual-footwear',
            'Ladies Clogs' => 'casual-footwear',
            'Mens Clogs' => 'casual-footwear',
            'Unisex Clogs' => 'casual-footwear',
            'Childrens Clogs' => 'casual-footwear',
            'Boys BTS' => 'casual-footwear',
            'Girls BTS' => 'casual-footwear',
            'Boys BTS FLFR' => 'casual-footwear',
            'Girls BTS FLFR' => 'casual-footwear',
            'Unisex BTS' => 'casual-footwear',
            'Mens Sports' => 'sports-trainers',
            'Ladies Sports' => 'sports-trainers',
            'Unisex Sports' => 'sports-trainers',
            'Boys Trainers' => 'sports-trainers',
            'Girls Trainers' => 'sports-trainers',
            'Football/Rugby Boots' => 'sports-trainers',
            'Golf Shoes' => 'sports-trainers',
            'Jackets' => 'jackets-fleeces',
            'Fleeces' => 'jackets-fleeces',
            'Body Warmers' => 'jackets-fleeces',
            'Trousers' => 'trousers-shorts',
            'Shorts' => 'trousers-shorts',
            'Tee Shirts' => 'tshirts-polos-shirts',
            'Polo Shirts' => 'tshirts-polos-shirts',
            'Shirts' => 'tshirts-polos-shirts',
            'Long Sleeve Shirts' => 'tshirts-polos-shirts',
            'Short Sleeve Shirts' => 'tshirts-polos-shirts',
            'Sweat Shirts' => 'sweatshirts-hoodies',
            'Hoodies' => 'sweatshirts-hoodies',
            'Joggers' => 'activewear-loungewear',
            'Leggings' => 'activewear-loungewear',
            'Bras' => 'activewear-loungewear',
            'Skirts & Dresses' => 'activewear-loungewear',
            'Loungewear' => 'activewear-loungewear',
            'Base Layer' => 'base-layers',
            'Boiler suit/Coverall' => 'coveralls',
            'Socks' => 'socks',
            'Headwear' => 'hats-beanies-scarves',
            'Beanies' => 'hats-beanies-scarves',
            'Hats' => 'hats-beanies-scarves',
            'Scarves' => 'hats-beanies-scarves',
            'Sunglasses Ladies' => 'sunglasses',
            'Sunglasses Mens' => 'sunglasses',
            'Back Packs' => 'bags-wallets',
            'Brief/Shoulder Bags' => 'bags-wallets',
            'Hand Bags' => 'bags-wallets',
            'Holdalls' => 'bags-wallets',
            'Shoe/Gym Bags' => 'bags-wallets',
            'Wallets' => 'bags-wallets',
            'BTS Accessories' => 'bags-wallets',
            'Insoles' => 'insoles-laces-shoe-care',
            'Laces' => 'insoles-laces-shoe-care',
            'Polishes/Cleaners' => 'insoles-laces-shoe-care',
            'Trees/Brushes/Horns' => 'insoles-laces-shoe-care',
            'Accessories' => 'other-accessories',
            'Miscellaneous Other' => 'other-accessories',
            'Gloves' => 'gloves',
            'Eyewear' => 'eye-face-protection',
            'PPE' => 'other-ppe',
        ],

        'gender_map' => [
            'Male' => 'Men',
            'Female' => 'Women',
            'Unisex' => 'Unisex',
        ],

        // Country name => ISO 3166-1 alpha-2. Unknown names are reported.
        'countries' => [
            'China' => 'CN',
            'Spain' => 'ES',
            'India' => 'IN',
            'Poland' => 'PL',
            'Portugal' => 'PT',
            'Italy' => 'IT',
            'Vietnam' => 'VN',
            'United Kingdom' => 'GB',
            'Indonesia' => 'ID',
            'Bangladesh' => 'BD',
            'Thailand' => 'TH',
            'Romania' => 'RO',
            'Turkey' => 'TR',
            'Pakistan' => 'PK',
            'Cambodia' => 'KH',
            'France' => 'FR',
        ],

        // Rows without real measurements carry these placeholders. They are
        // stored as null.
        'placeholder_measurements' => [
            'weight' => '999',
            'length' => '333',
            'width' => '222',
            'height' => '111',
        ],

        // Local seeding only.
        'fixture_file' => base_path('tests/Fixtures/VendorSync/Footsure/footsure.csv'),
    ],

    'images' => [
        // Total attempts per URL. Only connection errors, HTTP 5xx and 429
        // are retried; a 404 or 403 fails at once.
        'retries' => 3,

        // Seconds for one request in total, connect included. A host that
        // accepts the connection and then stalls runs to this limit.
        'timeout' => 60,

        // Seconds to wait for the connection alone (an unreachable host).
        'connect_timeout' => 10,

        // Pause between attempts, in milliseconds.
        'retry_backoff_ms' => 500,

        // Minimum milliseconds between the starts of two requests to one
        // host, across all workers. Every HTTP attempt takes its own slot.
        'throttle_ms' => 250,

        // Seconds the short lock that hands out those slots may be held, and
        // that a worker waits for it. The lock is never held during a request.
        'slot_lock_seconds' => 5,

        // Seconds kept back at the end of an image job (its timeout minus
        // this is the deadline for starting another download), for saving
        // media and writing the run report.
        'job_margin_seconds' => 60,

        'refresh_days' => 7,

        // Largest image, in megapixels, that is stored and converted. With
        // the GD driver a conversion needs about 8 MB of memory per
        // megapixel on top of the booted worker (about 54 MB). Measured with
        // all four conversions inline in one process: a 2560 x 2560 image
        // (6.55 MP) peaks at about 107 MB, and a 7.0 MP PNG of pure noise
        // near the 20 MB file cap at about 147 MB, against the 256M CLI
        // memory_limit. A larger image is replaced by its smaller rendition
        // when the vendor has one, and reported otherwise. Raise this only
        // together with the worker's memory limit, or after switching to
        // Imagick. The environment value wins: the server's .env must be
        // changed too (docs/vendorsync.md, Images).
        'max_megapixels' => (float) env('VENDORSYNC_IMAGE_MAX_MEGAPIXELS', 7.0),

        // Largest file accepted, in bytes. Media Library's own default of
        // 10 MB is raised to this for imports only.
        'max_file_bytes' => 20 * 1024 * 1024,
    ],

];
